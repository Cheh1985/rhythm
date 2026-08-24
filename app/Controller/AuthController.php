<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use PDOException;

final class AuthController
{
    public function loginForm(): void
    {
        if (Auth::user()) {
            \redirect('/');
        }
        \render('auth/login', ['error' => $_SESSION['flash_error'] ?? null], 'Вход');
        unset($_SESSION['flash_error']);
    }

    public function login(): never
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/login');
        }
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($login === '' || mb_strlen($login) > 190 || $password === '' || strlen($password) > 4096) {
            $_SESSION['flash_error'] = 'Неверные данные или слишком много попыток. Попробуйте позже.';
            \redirect('/login');
        }
        if (Auth::attempt($login, $password, $_SERVER['REMOTE_ADDR'] ?? '')) {
            \redirect('/');
        }
        $_SESSION['flash_error'] = 'Неверные данные или слишком много попыток. Попробуйте позже.';
        \redirect('/login');
    }

    public function registerForm(): void
    {
        if (Auth::user()) {
            \redirect('/');
        }
        \render('auth/register', ['error' => $_SESSION['flash_error'] ?? null], 'Регистрация');
        unset($_SESSION['flash_error']);
    }

    public function register(): never
    {
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = 'Сессия формы истекла.';
            \redirect('/register');
        }
        $login = trim((string) ($_POST['login'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!preg_match('/^[\pL\pN_.-]{3,80}$/u', $login) || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 10 || strlen($password) > 4096) {
            $_SESSION['flash_error'] = 'Логин: от 3 символов; корректный email; пароль: минимум 10 символов.';
            \redirect('/register');
        }
        try {
            Auth::register($login, $email, $password);
        } catch (PDOException) {
            $_SESSION['flash_error'] = 'Логин или email уже используется.';
            \redirect('/register');
        }
        \redirect('/');
    }

    public function logout(): never
    {
        if (Csrf::validate($_POST['_csrf'] ?? null)) {
            Auth::logout();
            header('Clear-Site-Data: "cache", "storage"');
        }
        \redirect('/login');
    }
}
