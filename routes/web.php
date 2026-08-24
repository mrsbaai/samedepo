<?php

declare(strict_types=1);

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Events\Authentication\AuthenticationEvent;
use App\Http\Controllers\Admin\UserSummaryController;
use App\Http\Controllers\Authentication\SocialiteController;
use App\Livewire\Account\AccountSettings;
use App\Livewire\Admin\AnnouncementEditor;
use App\Livewire\Admin\ContentManagement;
use App\Livewire\Admin\EnvironmentEditor;
use App\Livewire\Admin\FaqManager;
use App\Livewire\Admin\FraudIntelligence;
use App\Livewire\Admin\LegalPageEditor;
use App\Livewire\Admin\LogViewer;
use App\Livewire\Admin\PlatformSettings;
use App\Livewire\Admin\SupportSettings;
use App\Livewire\Admin\ThreatProtection;
use App\Livewire\Admin\TicketManager;
use App\Livewire\Admin\TreasuryOverview;
use App\Livewire\Admin\UserSearch;
use App\Livewire\Admin\WebsiteOwnerDetail;
use App\Livewire\Admin\WebsiteOwners;
use App\Livewire\Admin\WithdrawalQueue;
use App\Livewire\Admin\WithdrawalReview;
use App\Livewire\Admin\WithdrawalSettings as AdminWithdrawalSettings;
use App\Livewire\Authentication\ChangeEmail;
use App\Livewire\Authentication\ChangePassword;
use App\Livewire\Authentication\DeleteAccount;
use App\Livewire\Authentication\ForgotPassword;
use App\Livewire\Authentication\ResetPassword;
use App\Livewire\Authentication\SecurityHistory;
use App\Livewire\Authentication\SessionManager;
use App\Livewire\Authentication\Signin;
use App\Livewire\Authentication\Signup;
use App\Livewire\Authentication\TwoFactorChallenge;
use App\Livewire\Authentication\TwoFactorSecurity;
use App\Livewire\Authentication\VerifyEmailNotice;
use App\Livewire\Authentication\VerifyOtp;
use App\Livewire\Dashboard\AdminDashboard;
use App\Livewire\Dashboard\ApiKeys;
use App\Livewire\Dashboard\CustomerDetail;
use App\Livewire\Dashboard\Customers;
use App\Livewire\Dashboard\Deposits;
use App\Livewire\Dashboard\TransactionHistory;
use App\Livewire\Dashboard\UserDashboard;
use App\Livewire\Dashboard\WebhookSettings;
use App\Livewire\Dashboard\Withdraw;
use App\Livewire\Dashboard\WithdrawalSettings;
use App\Livewire\Demo\EmailInbox;
use App\Livewire\Support\SupportCenter;
use App\Livewire\Support\TicketCreate;
use App\Livewire\Support\TicketThread;
use App\Models\EmailChangeRequest;
use App\Models\PublicContentPage;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
||--------------------------------------------------------------------------
|| Health check
||--------------------------------------------------------------------------
*/

Route::get('/up', fn () => response('OK'))->name('health');

/*
|--------------------------------------------------------------------------
| Guest authentication routes
|--------------------------------------------------------------------------
*/

Route::middleware(['guest'])->group(function (): void {
    Route::get('/signin', Signin::class)->name('signin');
    Route::get('/signup', Signup::class)->name('signup');
    Route::get('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('/verify-otp', VerifyOtp::class)->name('password.otp');
    Route::get('/reset-password', ResetPassword::class)->name('password.reset');
    Route::get('/two-factor-challenge', TwoFactorChallenge::class)->name('two-factor.challenge');
});

/*
|--------------------------------------------------------------------------
| Email verification
|--------------------------------------------------------------------------
*/

Route::get('/verify-email', VerifyEmailNotice::class)
    ->middleware(['auth'])
    ->name('verification.notice');

Route::get('/verify-email/{id}/{hash}', function (Request $request, int $id, string $hash) {
    if (! URL::hasValidSignature($request)) {
        return redirect()->route('verification.notice')->withErrors(['verification' => 'The verification link has expired.']);
    }

    $user = User::query()->findOrFail($id);

    if (! hash_equals(sha1((string) $user->email), $hash)) {
        return redirect()->route('verification.notice')->withErrors(['verification' => 'The verification link is invalid.']);
    }

    if (! $user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();

        $user->forceFill(['is_active' => true])->save();

        event(new Verified($user));

        event(new AuthenticationEvent(
            type: AuthenticationEvent::EMAIL_VERIFIED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }

    $destination = ResolvePostSigninRedirect::for($user);

    return redirect($destination)->with('status', 'Your email address has been verified.');
})
    ->middleware(['auth'])
    ->name('verification.verify');

/*
|--------------------------------------------------------------------------
| Social authentication
|--------------------------------------------------------------------------
*/

Route::get('/social/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('social.redirect');
Route::get('/social/{provider}/callback', [SocialiteController::class, 'callback'])->name('social.callback');

/*
|--------------------------------------------------------------------------
| Authenticated user routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'owner'])->prefix('dashboard')->group(function (): void {
    Route::get('/', UserDashboard::class)->name('dashboard');
});

Route::middleware(['auth', 'owner'])->group(function (): void {
    Route::get('/customers', Customers::class)->name('customers');
    Route::get('/customers/{customer}', CustomerDetail::class)->name('customers.show');
    Route::get('/deposits', Deposits::class)->name('deposits');
    Route::get('/transactions', TransactionHistory::class)->name('transactions');
    Route::get('/api-keys', ApiKeys::class)->name('api-keys');
    Route::get('/webhook-settings', WebhookSettings::class)->name('webhook-settings');
    Route::get('/withdrawal-settings', WithdrawalSettings::class)->name('withdrawal-settings');
    Route::get('/withdraw/{network}', Withdraw::class)
        ->name('withdraw')
        ->whereIn('network', ['bitcoin', 'usdt-trc20', 'usdt-erc20']);
});

Route::middleware(['auth'])->group(function (): void {
    Route::post('/signout', function (): mixed {
        auth()->logout();
        session()->invalidate();

        return redirect()->route('signin');
    })->name('signout');
});

/*
|--------------------------------------------------------------------------
| Admin routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'admin'])->prefix('admin')->group(function (): void {
    Route::get('/', AdminDashboard::class)->name('admin.dashboard');
    Route::get('/environment', EnvironmentEditor::class)->name('admin.environment');
    Route::get('/logs', LogViewer::class)->name('admin.logs');
    Route::get('/faqs', FaqManager::class)->name('admin.faqs');
    Route::get('/legal/{slug}', LegalPageEditor::class)->whereIn('slug', ['privacy', 'terms'])->name('admin.legal.edit');
    Route::get('/tickets', TicketManager::class)->name('admin.tickets');
    Route::get('/tickets/{ticket}', TicketThread::class)->name('admin.tickets.show');
    Route::get('/users', UserSearch::class)->name('admin.users');
    Route::get('/users/{user}/summary', [UserSummaryController::class, 'show'])->name('admin.users.summary');
    Route::get('/users/{user}/summary.md', [UserSummaryController::class, 'markdown'])->name('admin.users.summary.markdown');
    Route::get('/support/settings', SupportSettings::class)->name('admin.support.settings');
    Route::get('/announcement', AnnouncementEditor::class)->name('admin.announcement');
    Route::get('/security/threats', ThreatProtection::class)->name('admin.security.threats');
    Route::get('/security/fraud', FraudIntelligence::class)->name('admin.security.fraud');
    Route::get('/platform-settings', PlatformSettings::class)->name('admin.platform-settings');
    Route::get('/withdrawal-settings', AdminWithdrawalSettings::class)->name('admin.withdrawal-settings');
    Route::get('/owners', WebsiteOwners::class)->name('admin.owners');
    Route::get('/owners/{owner}', WebsiteOwnerDetail::class)->name('admin.owners.show')->whereNumber('owner');
    Route::get('/withdrawals', WithdrawalQueue::class)->name('admin.withdrawals');
    Route::get('/withdrawals/{withdrawal}', WithdrawalReview::class)->name('admin.withdrawals.show')->whereNumber('withdrawal');
    Route::get('/treasury', TreasuryOverview::class)->name('admin.treasury');
    Route::get('/content', ContentManagement::class)->name('admin.content');
});

/*
|--------------------------------------------------------------------------
| Authenticated account routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function (): void {
    Route::get('/security', AccountSettings::class)->name('security');
    Route::get('/security/change-email', ChangeEmail::class)->name('email.change');
    Route::get('/security/change-password', ChangePassword::class)->name('password.change');
    Route::get('/security/two-factor', TwoFactorSecurity::class)->name('security.two-factor');
    Route::get('/security/sessions', SessionManager::class)->name('sessions.index');
    Route::get('/security/history', SecurityHistory::class)->name('security-history.index');
    Route::get('/security/delete', DeleteAccount::class)->name('security.delete');
});

/*
|--------------------------------------------------------------------------
| Email change verification
|--------------------------------------------------------------------------
*/

Route::get('/email/verify-change/{id}/{token}', function (Request $request, int $id, string $token): mixed {
    $changeRequest = EmailChangeRequest::query()->findOrFail($id);
    $user = $changeRequest->user;

    if ($user === null || ! $request->user()?->is($user)) {
        return redirect()->route('security')->withErrors(['verification' => 'The verification link is invalid or has expired.']);
    }

    if (! hash_equals((string) $changeRequest->verification_token, hash('sha256', $token))) {
        return redirect()->route('security')->withErrors(['verification' => 'The verification link is invalid or has expired.']);
    }

    if ($changeRequest->expires_at->isPast()) {
        return redirect()->route('security')->withErrors(['verification' => 'The verification link is invalid or has expired.']);
    }

    $oldEmail = $user->email;

    $user->forceFill([
        'email' => $changeRequest->pending_email,
        'email_verified_at' => now(),
    ])->save();

    $changeRequest->forceFill(['verified_at' => now()])->save();

    event(new AuthenticationEvent(
        type: AuthenticationEvent::EMAIL_CHANGED,
        user: $user,
        ipAddress: $request->ip(),
        userAgent: $request->userAgent(),
    ));

    $user->notify(new SecurityAlertNotification('email_changed', $oldEmail));

    return redirect()->route('security')->with('status', 'Your email address has been updated.');
})
    ->middleware(['auth'])
    ->name('email.verify-change');

/*
|--------------------------------------------------------------------------
| Account deletion recovery
|--------------------------------------------------------------------------
*/

Route::get('/security/delete/recover', function (Request $request): mixed {
    if (! URL::hasValidSignature($request)) {
        return redirect()->route('signin')->withErrors(['email' => 'The recovery link is invalid or has expired.']);
    }

    $id = $request->integer('id');
    $email = $request->string('email')->lower()->toString();

    $user = User::query()->findOrFail($id);

    if (! hash_equals((string) $user->email, $email) || ! $user->hasRequestedDeletion()) {
        return redirect()->route('signin')->withErrors(['email' => 'The recovery link is invalid or has expired.']);
    }

    $user->forceFill([
        'is_active' => true,
        'deletion_requested_at' => null,
    ])->save();

    event(new AuthenticationEvent(
        type: AuthenticationEvent::ACCOUNT_DELETION_RECOVERED,
        user: $user,
        ipAddress: $request->ip(),
        userAgent: $request->userAgent(),
    ));

    return redirect()->route('signin')->with('status', 'Your account has been recovered. Please sign in.');
})->name('security.delete.recover');

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::view('/', 'public.landing')->name('public.landing');

Route::view('/api-docs', 'public.api-docs')->name('public.api-docs');

Route::get('/privacy', function () {
    $page = PublicContentPage::query()->firstOrCreate(
        ['type' => 'privacy'],
        ['content' => '']
    );

    return view('pages.privacy', ['page' => $page]);
})->name('privacy');

Route::get('/terms', function () {
    $page = PublicContentPage::query()->firstOrCreate(
        ['type' => 'terms'],
        ['content' => '']
    );

    return view('pages.terms', ['page' => $page]);
})->name('terms');

Route::middleware(['auth'])->prefix('support')->group(function (): void {
    Route::get('/', SupportCenter::class)->name('support');
    Route::get('/tickets/create', TicketCreate::class)->name('support.tickets.create');
    Route::get('/tickets/{ticket}', TicketThread::class)->name('support.tickets.show');
});

// TEMPORARY: Flux Pro skill test — delete after review.
Route::get('/demo/inbox', EmailInbox::class)->name('demo.inbox');

Route::fallback(fn () => abort(404));
