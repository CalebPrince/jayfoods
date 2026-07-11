<?php

declare(strict_types=1);

/**
 * Contact / catering enquiries.
 *   POST   /api/v1/messages                 (public)  create()
 *   GET    /api/v1/admin/messages           (admin)   index()
 *   PATCH  /api/v1/admin/messages/{id}       (admin)   markRead()
 *   DELETE /api/v1/admin/messages/{id}       (admin)   destroy()
 */
final class MessageController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function create(): void
    {
        $input   = json_decode((string) file_get_contents('php://input'), true);
        $name    = trim((string) ($input['name'] ?? ''));
        $phone   = trim((string) ($input['phone'] ?? ''));
        $email   = trim((string) ($input['email'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        $errors = [];
        if ($name === '')    { $errors['name']    = 'Your name is required.'; }
        if ($message === '') { $errors['message'] = 'Please enter a message.'; }
        if ($phone === '' && $email === '') {
            $errors['phone'] = 'Add a phone number or email so we can reply.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email address is not valid.';
        }

        if ($errors) {
            Response::json(['error' => 'Validation failed.', 'fields' => $errors], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO messages (name, phone, email, message)
             VALUES (:name, :phone, :email, :message)'
        );
        $stmt->execute([
            ':name'    => $name,
            ':phone'   => $phone,
            ':email'   => $email,
            ':message' => $message,
        ]);

        EmailNotifications::contactReceived([
            'name' => $name, 'phone' => $phone, 'email' => $email, 'message' => $message,
        ]);

        Response::json(['data' => ['ok' => true]], 201);
    }

    public function index(): void
    {
        $rows = $this->db->query(
            'SELECT id, name, phone, email, message, is_read, created_at
               FROM messages
           ORDER BY created_at DESC, id DESC'
        )->fetchAll();

        $data = array_map(static function (array $r): array {
            return [
                'id'         => (int) $r['id'],
                'name'       => $r['name'],
                'phone'      => $r['phone'],
                'email'      => $r['email'],
                'message'    => $r['message'],
                'is_read'    => (bool) $r['is_read'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        Response::json(['data' => $data]);
    }

    public function markRead(int $id): void
    {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $read  = !empty($input['is_read']) ? 1 : 0;

        $stmt = $this->db->prepare('UPDATE messages SET is_read = :read WHERE id = :id');
        $stmt->execute([':read' => $read, ':id' => $id]);

        Response::json(['data' => ['ok' => true, 'is_read' => (bool) $read]]);
    }

    public function destroy(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM messages WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Response::json(['data' => ['ok' => true]]);
    }
}
