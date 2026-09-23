<?= $this->extend('layouts/admin') ?>

<?= $this->section('title') ?>My Profile — Delami Shipping<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-lg-6">
        <h1 class="h4 mb-3">My Profile</h1>
        <div class="card">
            <div class="card-body">
                <form method="post" action="<?= site_url('admin/profile') ?>" autocomplete="off">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-control" id="username" value="<?= esc($user->username) ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input class="form-control" id="email" name="email" type="email"
                               value="<?= esc(old('email', $user->email), 'attr') ?>" required>
                    </div>

                    <hr>
                    <p class="text-muted small mb-2">Leave the password fields blank to keep your current password.</p>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password</label>
                        <input class="form-control" id="new_password" name="new_password" type="password"
                               placeholder="min. 8 characters" autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="pass_confirm">Confirm new password</label>
                        <input class="form-control" id="pass_confirm" name="pass_confirm" type="password"
                               autocomplete="new-password">
                    </div>

                    <hr>
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password <span class="text-danger">*</span></label>
                        <input class="form-control" id="current_password" name="current_password" type="password"
                               placeholder="required to save any change" autocomplete="current-password" required>
                    </div>

                    <button class="btn btn-primary" type="submit">Save changes</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
