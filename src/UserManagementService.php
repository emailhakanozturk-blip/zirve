<?php
declare(strict_types=1);

namespace PersonelHakedis;

use PDO;
use RuntimeException;
use Throwable;

final class UserManagementService
{
    public function __construct(private PDO $db, private ?int $actorId, private ?string $ip) {}

    public function overview(array $filters = []): array
    {
        return [
            'summary' => $this->summary(),
            'users' => $this->users($filters),
            'roles' => $this->roles(),
        ];
    }

    public function summary(): array
    {
        $sql = "SELECT
            (SELECT COUNT(*) FROM kullanicilar) toplam_kullanici,
            (SELECT COUNT(*) FROM kullanicilar WHERE aktif=1) aktif_kullanici,
            (SELECT COUNT(*) FROM roller WHERE aktif=1) aktif_rol,
            (SELECT COUNT(*) FROM yetkiler WHERE aktif=1) tanimli_yetki,
            (SELECT COUNT(*) FROM islem_loglari WHERE DATE(created_at)=CURRENT_DATE) bugunku_islem";
        $row = $this->db->query($sql)->fetch() ?: [];
        return array_map('intval', $row);
    }

    public function users(array $filters = []): array
    {
        $sql = "SELECT k.id,k.ad_soyad,k.eposta,k.aktif,k.son_giris_at,k.created_at,
                       GROUP_CONCAT(DISTINCT r.ad ORDER BY r.ad SEPARATOR ', ') rol_adlari,
                       GROUP_CONCAT(DISTINCT r.id ORDER BY r.id) rol_idleri,
                       (SELECT COUNT(DISTINCT effective.yetki_id) FROM (
                           SELECT kr2.kullanici_id,ry.yetki_id FROM kullanici_rolleri kr2 JOIN rol_yetkileri ry ON ry.rol_id=kr2.rol_id
                           UNION ALL
                           SELECT ky.kullanici_id,ky.yetki_id FROM kullanici_yetkileri ky WHERE ky.izinli=1
                       ) effective WHERE effective.kullanici_id=k.id) yetki_sayisi
                FROM kullanicilar k
                LEFT JOIN kullanici_rolleri kr ON kr.kullanici_id=k.id
                LEFT JOIN roller r ON r.id=kr.rol_id AND r.aktif=1
                WHERE 1=1";
        $params = [];
        $status = (string)($filters['durum'] ?? 'all');
        if ($status === 'active') $sql .= ' AND k.aktif=1';
        if ($status === 'passive') $sql .= ' AND k.aktif=0';
        $search = trim((string)($filters['ara'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (k.ad_soyad LIKE :search OR k.eposta LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' GROUP BY k.id ORDER BY k.aktif DESC,k.ad_soyad';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    public function roles(): array
    {
        return $this->db->query("SELECT r.id,r.ad,r.rol_anahtari,r.aciklama,r.aktif,COUNT(DISTINCT kr.kullanici_id) kullanici_sayisi
            FROM roller r LEFT JOIN kullanici_rolleri kr ON kr.rol_id=r.id
            WHERE r.aktif=1 GROUP BY r.id ORDER BY r.ad")->fetchAll();
    }

    public function saveUser(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $name = mb_substr(trim((string)($data['ad_soyad'] ?? '')), 0, 150);
        $email = mb_strtolower(mb_substr(trim((string)($data['eposta'] ?? '')), 0, 190));
        $password = (string)($data['parola'] ?? '');
        $active = !empty($data['aktif']) ? 1 : 0;
        $roleIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['rol_idleri'] ?? [])))));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ad soyad ve geçerli e-posta zorunludur.');
        if (($id === 0 || $password !== '') && mb_strlen($password) < 8) throw new RuntimeException('Parola en az 8 karakter olmalıdır.');
        $duplicate = $this->db->prepare('SELECT id FROM kullanicilar WHERE eposta=:eposta AND id<>:id LIMIT 1');
        $duplicate->execute(['eposta'=>$email,'id'=>$id]);
        if ($duplicate->fetchColumn()) throw new RuntimeException('Bu e-posta adresi başka bir kullanıcıda kayıtlıdır.');

        $this->db->beginTransaction();
        try {
            if ($id > 0) {
                $old = $this->userRecord($id);
                if (!$old) throw new RuntimeException('Kullanıcı bulunamadı.');
                $params = ['id'=>$id,'ad_soyad'=>$name,'eposta'=>$email,'aktif'=>$active];
                $passwordSql = '';
                if ($password !== '') {
                    $passwordSql = ',parola_hash=:parola_hash';
                    $params['parola_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $this->db->prepare('UPDATE kullanicilar SET ad_soyad=:ad_soyad,eposta=:eposta,aktif=:aktif'.$passwordSql.' WHERE id=:id')->execute($params);
                $action = 'kullanici_guncelle';
            } else {
                $old = null;
                $statement = $this->db->prepare('INSERT INTO kullanicilar(ad_soyad,eposta,parola_hash,aktif) VALUES(:ad_soyad,:eposta,:parola_hash,:aktif)');
                $statement->execute(['ad_soyad'=>$name,'eposta'=>$email,'parola_hash'=>password_hash($password,PASSWORD_DEFAULT),'aktif'=>$active]);
                $id = (int)$this->db->lastInsertId();
                $action = 'kullanici_ekle';
            }
            $this->syncRoles($id, $roleIds);
            $this->log($action, 'kullanicilar', $id, $old, $this->userRecord($id));
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function setStatus(int $id, bool $active): void
    {
        if ($id < 1) throw new RuntimeException('Kullanıcı seçimi zorunludur.');
        if ($this->actorId === $id && !$active) throw new RuntimeException('Kendi hesabınızı pasife alamazsınız.');
        $old = $this->userRecord($id);
        if (!$old) throw new RuntimeException('Kullanıcı bulunamadı.');
        $this->db->prepare('UPDATE kullanicilar SET aktif=? WHERE id=?')->execute([$active?1:0,$id]);
        $this->log('kullanici_durum', 'kullanicilar', $id, $old, $this->userRecord($id));
    }

    public function resetPassword(int $id, string $password): void
    {
        if (!$this->userRecord($id)) throw new RuntimeException('Kullanıcı bulunamadı.');
        if (mb_strlen($password) < 8) throw new RuntimeException('Yeni parola en az 8 karakter olmalıdır.');
        $this->db->prepare('UPDATE kullanicilar SET parola_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        $this->log('parola_yenile', 'kullanicilar', $id, null, ['parola'=>'[gizli]']);
    }

    public function authorization(int $userId = 0): array
    {
        $users = $this->db->query('SELECT id,ad_soyad,eposta,aktif FROM kullanicilar ORDER BY aktif DESC,ad_soyad')->fetchAll();
        if ($userId < 1) $userId = (int)($users[0]['id'] ?? 0);
        $user = $userId ? $this->userRecord($userId) : null;
        $permissions = $this->db->query('SELECT id,yetki_anahtari,ad,menu_grubu,menu_adi,yetki_turu,sira FROM yetkiler WHERE aktif=1 ORDER BY sira,id')->fetchAll();
        if (!$user) return ['users'=>$users,'selected_user'=>null,'permissions'=>$permissions,'selected_ids'=>[],'role_ids'=>[],'override_ids'=>[]];

        $roleStatement = $this->db->prepare('SELECT rol_id FROM kullanici_rolleri WHERE kullanici_id=?');
        $roleStatement->execute([$userId]);
        $roleIds = array_map('intval', $roleStatement->fetchAll(PDO::FETCH_COLUMN));
        $baselineStatement = $this->db->prepare('SELECT DISTINCT ry.yetki_id FROM rol_yetkileri ry JOIN kullanici_rolleri kr ON kr.rol_id=ry.rol_id WHERE kr.kullanici_id=?');
        $baselineStatement->execute([$userId]);
        $selected = array_fill_keys(array_map('intval',$baselineStatement->fetchAll(PDO::FETCH_COLUMN)), true);
        $overrideStatement = $this->db->prepare('SELECT yetki_id,izinli FROM kullanici_yetkileri WHERE kullanici_id=?');
        $overrideStatement->execute([$userId]);
        $overrides = [];
        foreach ($overrideStatement->fetchAll() as $row) {
            $permissionId = (int)$row['yetki_id'];
            $overrides[$permissionId] = (int)$row['izinli'];
            if ((int)$row['izinli'] === 1) $selected[$permissionId] = true; else unset($selected[$permissionId]);
        }
        return ['users'=>$users,'selected_user'=>$user,'permissions'=>$permissions,'selected_ids'=>array_map('intval',array_keys($selected)),'role_ids'=>$roleIds,'override_ids'=>$overrides];
    }

    public function savePermissions(int $userId, array $permissionIds): void
    {
        if (!$this->userRecord($userId)) throw new RuntimeException('Kullanıcı bulunamadı.');
        $selected = array_fill_keys(array_values(array_unique(array_filter(array_map('intval',$permissionIds)))), true);
        $baselineStatement = $this->db->prepare('SELECT DISTINCT ry.yetki_id FROM rol_yetkileri ry JOIN kullanici_rolleri kr ON kr.rol_id=ry.rol_id WHERE kr.kullanici_id=?');
        $baselineStatement->execute([$userId]);
        $baseline = array_fill_keys(array_map('intval',$baselineStatement->fetchAll(PDO::FETCH_COLUMN)), true);
        $allIds = array_map('intval',$this->db->query('SELECT id FROM yetkiler WHERE aktif=1')->fetchAll(PDO::FETCH_COLUMN));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM kullanici_yetkileri WHERE kullanici_id=?')->execute([$userId]);
            $insert = $this->db->prepare('INSERT INTO kullanici_yetkileri(kullanici_id,yetki_id,izinli) VALUES(?,?,?)');
            foreach ($allIds as $permissionId) {
                $isSelected = isset($selected[$permissionId]);
                $fromRole = isset($baseline[$permissionId]);
                if ($isSelected !== $fromRole) $insert->execute([$userId,$permissionId,$isSelected?1:0]);
            }
            $this->log('yetki_guncelle', 'kullanicilar', $userId, null, ['yetki_idleri'=>array_keys($selected)]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $exception;
        }
    }

    public function logs(array $filters = []): array
    {
        $sql = 'SELECT l.*,k.ad_soyad kullanici_adi,k.eposta FROM islem_loglari l LEFT JOIN kullanicilar k ON k.id=l.kullanici_id WHERE 1=1';
        $params = [];
        if ((int)($filters['kullanici_id'] ?? 0) > 0) {$sql .= ' AND l.kullanici_id=:user';$params['user']=(int)$filters['kullanici_id'];}
        $search = trim((string)($filters['ara'] ?? ''));
        if ($search !== '') {$sql .= " AND CONCAT_WS(' ',l.islem,l.tablo_adi,l.alan_adi,k.ad_soyad,k.eposta) LIKE :search";$params['search']='%'.$search.'%';}
        $sql .= ' ORDER BY l.id DESC LIMIT 250';
        $statement = $this->db->prepare($sql);$statement->execute($params);
        return ['rows'=>$statement->fetchAll(),'users'=>$this->db->query('SELECT id,ad_soyad FROM kullanicilar ORDER BY ad_soyad')->fetchAll()];
    }

    private function syncRoles(int $userId, array $roleIds): void
    {
        $this->db->prepare('DELETE FROM kullanici_rolleri WHERE kullanici_id=?')->execute([$userId]);
        $insert = $this->db->prepare('INSERT INTO kullanici_rolleri(kullanici_id,rol_id) SELECT ?,id FROM roller WHERE id=? AND aktif=1');
        foreach ($roleIds as $roleId) $insert->execute([$userId,$roleId]);
    }

    private function userRecord(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT id,ad_soyad,eposta,aktif,son_giris_at,created_at FROM kullanicilar WHERE id=?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function log(string $action, string $table, int $recordId, ?array $old, ?array $new): void
    {
        $statement = $this->db->prepare('INSERT INTO islem_loglari(kullanici_id,islem,tablo_adi,kayit_id,alan_adi,eski_deger,yeni_deger,ip_adresi) VALUES(?,?,?,?,?,?,?,?)');
        $statement->execute([
            $this->actorId,
            $action,
            $table,
            $recordId,
            null,
            $old ? json_encode($old,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            $new ? json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            $this->ip,
        ]);
    }
}
