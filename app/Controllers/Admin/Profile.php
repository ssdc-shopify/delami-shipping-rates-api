<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Models\UserModel;

class Profile extends BaseController
{
    public function index()
    {
        return view('admin/profile/index', ['user' => auth()->user()]);
    }

    /**
     * POST /admin/profile — update email and/or password.
     * Requires the current password to authorize any change.
     */
    public function update()
    {
        $user = auth()->user();

        if (! $this->validate([
            'current_password' => 'required',
            'email'            => 'required|valid_email',
            'new_password'     => 'permit_empty|min_length[8]',
            'pass_confirm'     => 'permit_empty|matches[new_password]',
        ])) {
            return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
        }

        // Verify the current password before allowing any change.
        $credentials = ['email' => $user->email, 'password' => (string) $this->request->getPost('current_password')];
        if (! auth()->check($credentials)->isOK()) {
            return redirect()->back()->withInput()->with('error', 'Current password is incorrect.');
        }

        $users    = model(UserModel::class);
        $newEmail = strtolower(trim((string) $this->request->getPost('email')));
        $newPass  = (string) $this->request->getPost('new_password');

        // Reject an email already used by another account.
        $existing = $users->findByCredentials(['email' => $newEmail]);
        if ($existing !== null && $existing->id !== $user->id) {
            return redirect()->back()->withInput()->with('error', 'That email is already in use.');
        }

        if ($newEmail !== strtolower((string) $user->email)) {
            $user->email = $newEmail;
        }
        if ($newPass !== '') {
            $user->password = $newPass;
        }

        $users->save($user);

        return redirect()->to(site_url('admin/profile'))->with('message', 'Profile updated.');
    }
}
