<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Support\Db;
use App\Support\Validator;

final class UsersController
{
    private const COLUMNS = '"id", "name", "email", "role", "createdAt"';

    public static function index(Request $r): Response
    {
        return Response::json(Db::all('SELECT ' . self::COLUMNS . ' FROM "User" ORDER BY "createdAt" ASC'));
    }

    public static function create(Request $r): Response
    {
        $data = Validator::validate($r->body(), [
            'name' => ['required' => true, 'min' => 2, 'max' => 200],
            'email' => ['required' => true, 'email' => true, 'max' => 255],
            'password' => ['required' => true, 'min' => 8, 'max' => 72],
            'role' => ['enum' => ['ADMIN', 'MEMBER']],
        ]);
        $email = strtolower(trim($data['email']));
        if (Db::value('SELECT 1 FROM "User" WHERE "email" = ?', [$email])) {
            throw ApiError::conflict('E-Mail-Adresse bereits registriert');
        }
        $id = Db::insert('User', [
            'name' => $data['name'],
            'email' => $email,
            'passwordHash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role' => $data['role'] ?? 'MEMBER',
        ]);
        return Response::json(Db::one('SELECT ' . self::COLUMNS . ' FROM "User" WHERE "id" = ?', [$id]), 201);
    }

    public static function update(Request $r): Response
    {
        $id = $r->param('id');
        $data = Validator::validate($r->body(), [
            'name' => ['min' => 2, 'max' => 200],
            'role' => ['enum' => ['ADMIN', 'MEMBER']],
            'password' => ['min' => 8, 'max' => 72],
        ], partial: true);

        $update = array_intersect_key($data, ['name' => 1, 'role' => 1]);
        if (isset($data['password'])) {
            $update['passwordHash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        Db::transaction(static function () use ($id, $update) {
            $user = Db::require('User', $id, 'Benutzer nicht gefunden');
            if (($update['role'] ?? $user['role']) !== 'ADMIN' && $user['role'] === 'ADMIN' && self::adminCount() <= 1) {
                throw ApiError::badRequest('Der letzte Administrator kann nicht herabgestuft werden');
            }
            Db::update('User', $id, $update);
        });

        return Response::json(Db::one('SELECT ' . self::COLUMNS . ' FROM "User" WHERE "id" = ?', [$id]));
    }

    public static function delete(Request $r): Response
    {
        $id = $r->param('id');
        if ($id === $r->user['id']) {
            throw ApiError::badRequest('Das eigene Konto kann nicht gelöscht werden');
        }
        Db::delete('User', $id, 'Benutzer nicht gefunden');
        return Response::noContent();
    }

    private static function adminCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM "User" WHERE "role" = \'ADMIN\'');
    }
}
