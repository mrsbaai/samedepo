<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Response;

class UserSummaryController
{
    public function show(User $user): mixed
    {
        return view('admin.users.summary', compact('user'));
    }

    public function markdown(User $user): Response
    {
        $status = $user->is_active ? 'Active' : 'Inactive';

        $content = <<<MD
# User Summary

- **Email:** {$user->email}
- **Signed up:** {$user->created_at->toDateTimeString()}
- **Status:** {$status}
MD;

        return response($content, 200, ['Content-Type' => 'text/markdown']);
    }
}
