<?php

use App\Livewire\Support\TicketThread;
use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\SupportSetting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('non-admins cannot use the AI suggest reply feature', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($user)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'test')
        ->call('suggestReply')
        ->assertNotSet('body', 'AI generated reply');
});

test('admin can generate an AI reply that fills the editor body', function () {
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'content' => '<p>Sure, here is the help you need.</p>',
                    ],
                ],
            ],
        ], 200),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);
    $ticket->messages()->create(['user_id' => $user->id, 'body' => 'I need help with my account.']);

    SupportSetting::current()->update([
        'special_instructions' => 'Be extra polite.',
        'service_description' => 'Simple invoicing for freelancers.',
        'service_use_case' => 'Create invoices, track payments, download reports.',
    ]);
    Faq::create(['question' => 'How do I reset?', 'answer' => 'Click forgot password.', 'position' => 1]);
    LegalPage::query()->where('slug', 'terms')->update(['content' => '<p>No refunds without approval.</p>']);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', 'Notes: check their payment.')
        ->call('suggestReply')
        ->assertHasNoErrors()
        ->assertSet('body', '<p>Sure, here is the help you need.</p>');

    Http::assertSent(function ($request) use ($user) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? '';

        return str_contains($content, $user->email)
            && str_contains($content, config('app.name'))
            && str_contains($content, 'Simple invoicing for freelancers.')
            && str_contains($content, 'Create invoices, track payments, download reports.')
            && str_contains($content, 'Be extra polite.')
            && str_contains($content, 'How do I reset?')
            && str_contains($content, 'check their payment')
            && str_contains($content, 'No refunds without approval');
    });
});

test('AI suggestion shows an error when the API call fails', function () {
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([], 500),
    ]);

    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $ticket = SupportTicket::create(['user_id' => $user->id, 'subject' => 'Help', 'status' => 'open', 'last_message_at' => now()]);

    Livewire::actingAs($admin)
        ->test(TicketThread::class, ['ticket' => $ticket])
        ->set('body', '')
        ->call('suggestReply')
        ->assertHasErrors(['body']);
});
