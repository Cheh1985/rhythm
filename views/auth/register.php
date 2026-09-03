<section class="auth-card">
    <div class="auth-language"><?php require APP_ROOT . '/views/partials/language-switch.php'; ?></div>
    <div class="brand auth-brand"><span class="brand-mark"><?= e(locale() === 'en' ? 'R' : 'Р') ?></span><span>Ритм</span></div>
    <p class="eyebrow">Начало работы</p>
    <h1>Создать аккаунт</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= te($error) ?></div><?php endif; ?>
    <?php if ($success ?? null): ?><div class="alert alert-success"><?= te($success) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/register')) ?>" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <label>Логин<input name="login" minlength="3" maxlength="80" autocomplete="username" required></label>
        <label>Email<input type="email" name="email" maxlength="190" autocomplete="email" required></label>
        <label>Пароль <small>минимум 10 символов</small><input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
        <button class="button button-primary button-wide" type="submit">Создать аккаунт</button>
    </form>
    <p class="auth-switch">Уже есть аккаунт? <a href="<?= e(url('/login')) ?>">Войти</a></p>
</section>
