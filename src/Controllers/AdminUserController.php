<?php
declare(strict_types=1);

/**
 * Admin users and their roles (super admin only; see Roles). The super admin
 * cannot change their own role or switch themselves off, and there is always
 * at least one active super admin left.
 */
final class AdminUserController
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    public function index(): void
    {
        $rows = $this->db->query('SELECT id,name,email,role,is_active,created_at FROM admins ORDER BY name,id')->fetchAll();
        Response::json([
            'data' => array_map(static fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'is_active' => (bool) $r['is_active'], 'created_at' => $r['created_at']], $rows),
            'current_admin_id' => Auth::id(),
            'roles' => Roles::LABELS,
        ]);
    }

    public function save(): void
    {
        $in = json_decode((string) file_get_contents('php://input'), true);
        Response::json(...self::store($this->db, is_array($in) ? $in : [], (int) Auth::id()));
    }

    public function destroy(int $id): void
    {
        Response::json(...self::remove($this->db, $id, (int) Auth::id()));
    }

    /** @return array{0:array,1:int} the response body and status */
    public static function store(PDO $db, array $in, int $actorId): array
    {
        $id = (int) ($in['id'] ?? 0);
        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        $active = !empty($in['is_active']) ? 1 : 0;
        $role = (string) ($in['role'] ?? 'staff');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || (!$id && strlen($password) < 8) || ($password !== '' && strlen($password) < 8)) {
            return [['error' => 'Enter a name, valid email and a password of at least 8 characters.'], 422];
        }
        if (!Roles::valid($role)) return [['error' => 'Choose Super admin, Admin or Staff.'], 422];
        $current = null;
        if ($id) {
            $s = $db->prepare('SELECT id,role,is_active FROM admins WHERE id=:id');
            $s->execute([':id' => $id]);
            $current = $s->fetch();
            if (!$current) return [['error' => 'Account not found.'], 404];
        }
        if ($id === $actorId && !$active) return [['error' => 'You cannot deactivate your own account.'], 422];
        if ($id === $actorId && $role !== $current['role']) return [['error' => 'You cannot change your own role. Another super admin can.'], 422];
        if ($current && $current['role'] === 'super_admin' && (int) $current['is_active'] === 1 && ($role !== 'super_admin' || !$active) && self::otherActiveSuperAdmins($db, $id) === 0) {
            return [['error' => 'Keep at least one active super admin.'], 422];
        }
        try {
            if ($id) {
                $sql = 'UPDATE admins SET name=:name,email=:email,role=:role,is_active=:active' . ($password !== '' ? ',password_hash=:hash' : '') . ' WHERE id=:id';
                $p = [':name' => $name, ':email' => $email, ':role' => $role, ':active' => $active, ':id' => $id];
                if ($password !== '') $p[':hash'] = password_hash($password, PASSWORD_DEFAULT);
            } else {
                $sql = 'INSERT INTO admins(name,email,password_hash,role,is_active) VALUES(:name,:email,:hash,:role,:active)';
                $p = [':name' => $name, ':email' => $email, ':hash' => password_hash($password, PASSWORD_DEFAULT), ':role' => $role, ':active' => $active];
            }
            $db->prepare($sql)->execute($p);
            return [['data' => ['ok' => true]], 200];
        } catch (PDOException $e) {
            return [['error' => 'That email is already in use.'], 409];
        }
    }

    /** @return array{0:array,1:int} */
    public static function remove(PDO $db, int $id, int $actorId): array
    {
        if ($id === $actorId) return [['error' => 'You cannot delete your own account.'], 422];
        $s = $db->prepare('SELECT role,is_active FROM admins WHERE id=:id');
        $s->execute([':id' => $id]);
        $row = $s->fetch();
        if ($row && $row['role'] === 'super_admin' && (int) $row['is_active'] === 1 && self::otherActiveSuperAdmins($db, $id) === 0) {
            return [['error' => 'Keep at least one active super admin.'], 422];
        }
        $db->prepare('DELETE FROM admins WHERE id=:id')->execute([':id' => $id]);
        return [['data' => ['ok' => true]], 200];
    }

    private static function otherActiveSuperAdmins(PDO $db, int $exceptId): int
    {
        $s = $db->prepare("SELECT COUNT(*) FROM admins WHERE role='super_admin' AND is_active=1 AND id!=:id");
        $s->execute([':id' => $exceptId]);
        return (int) $s->fetchColumn();
    }
}
