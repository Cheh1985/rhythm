<section class="auth-card">
    <div class="auth-language"><?php require APP_ROOT . '/views/partials/language-switch.php'; ?></div>
    <div class="brand auth-brand"><span class="brand-mark"><?= e(locale() === 'en' ? 'R' : 'Р') ?></span><span>Ритм</span></div>
    <p class="eyebrow">Дневник тренировок</p>
    <h1>С возвращением</h1>
    <p class="muted">План, фактические подходы и прогресс — без лишнего шума.</p>
    <?php if ($error): ?><div class="alert alert-error"><?= te($error) ?></div><?php endif; ?>
    <?php if ($success ?? null): ?><div class="alert alert-success"><?= te($success) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" class="stack-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>">
        <label>Логин или email<input name="login" autocomplete="username" required autofocus></label>
        <label>Пароль<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button button-primary button-wide" type="submit">Войти</button>
    </form>
    <p class="auth-switch">Впервые здесь? <a href="<?= e(url('/register')) ?>">Создать аккаунт</a></p>
</section>
