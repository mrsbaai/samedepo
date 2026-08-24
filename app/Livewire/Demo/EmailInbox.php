<?php

declare(strict_types=1);

namespace App\Livewire\Demo;

use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.demo')]
#[Title('Inbox')]
class EmailInbox extends Component
{
    public string $folder = 'inbox';

    public string $search = '';

    public ?int $selectedId = 1;

    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    // Compose form
    public string $to = '';

    public string $subject = '';

    public string $body = '';

    public function mount(): void
    {
        $this->messages = [
            ['id' => 1, 'folder' => 'inbox', 'from' => 'Maya Chen', 'email' => 'maya@lumenlabs.io', 'subject' => 'Q3 roadmap review — final draft attached', 'preview' => 'Hi! I finished the final pass on the roadmap deck. The biggest change is that we moved the billing migration up a sprint…', 'body' => "Hi!\n\nI finished the final pass on the roadmap deck. The biggest change is that we moved the billing migration up a sprint, since the payments team freed up earlier than expected.\n\nKey changes:\n- Billing migration now lands in Sprint 14\n- The mobile beta slips one week (App Store review buffer)\n- Analytics dashboard is unchanged\n\nCould you review before Thursday's leadership sync? Comments directly in the deck are fine.\n\nThanks!\nMaya", 'time' => '10:42 AM', 'unread' => true, 'starred' => true, 'label' => 'Work'],
            ['id' => 2, 'folder' => 'inbox', 'from' => 'GitHub', 'email' => 'notifications@github.com', 'subject' => '[acme/platform] PR #482: Fix race condition in queue worker', 'preview' => 'jordan-dev requested your review on: Fix race condition in queue worker. This resolves the duplicate-job issue reported in #479…', 'body' => "jordan-dev requested your review on:\n\nPR #482 — Fix race condition in queue worker\n\nThis resolves the duplicate-job issue reported in #479 by acquiring an advisory lock before claiming a job. Includes a regression test that reproduces the original failure under parallel workers.\n\nFiles changed: 4 (+118, -32)", 'time' => '9:58 AM', 'unread' => true, 'starred' => false, 'label' => 'Dev'],
            ['id' => 3, 'folder' => 'inbox', 'from' => 'Priya Natarajan', 'email' => 'priya@northwind.co', 'subject' => 'Re: Partnership proposal — next steps', 'preview' => 'Great call today. As discussed, we would like to start with a 3-month pilot covering two of our regional teams…', 'body' => "Great call today.\n\nAs discussed, we'd like to start with a 3-month pilot covering two of our regional teams. Legal will send the draft MSA by Friday.\n\nOn pricing: the volume tier we discussed works, but procurement will want net-45 terms. Is that workable on your side?\n\nBest,\nPriya", 'time' => '9:12 AM', 'unread' => true, 'starred' => false, 'label' => 'Sales'],
            ['id' => 4, 'folder' => 'inbox', 'from' => 'Stripe', 'email' => 'receipts@stripe.com', 'subject' => 'Your invoice from Acme Cloud ($249.00)', 'preview' => 'Receipt for invoice #INV-2031. Amount paid: $249.00. Payment method: Visa •••• 4242…', 'body' => "Receipt for invoice #INV-2031\n\nAmount paid: \$249.00\nPayment method: Visa •••• 4242\nDate: August 11, 2026\n\nThis is a receipt for your recent payment. No action is required.", 'time' => 'Yesterday', 'unread' => false, 'starred' => false, 'label' => null],
            ['id' => 5, 'folder' => 'inbox', 'from' => 'Tom Okafor', 'email' => 'tom@acme.com', 'subject' => 'Lunch Thursday?', 'preview' => 'A few of us are trying the new ramen place near the office on Thursday. You in? We are leaving around 12:15…', 'body' => "A few of us are trying the new ramen place near the office on Thursday. You in?\n\nWe're leaving around 12:15 from the lobby. They apparently have a tsukemen that's worth the queue.\n\nTom", 'time' => 'Yesterday', 'unread' => false, 'starred' => true, 'label' => null],
            ['id' => 6, 'folder' => 'inbox', 'from' => 'Laravel News', 'email' => 'newsletter@laravel-news.com', 'subject' => 'This week: Livewire 4 tips, queue batching deep dive', 'preview' => 'The latest from the Laravel ecosystem: five Livewire 4 patterns you should be using, a deep dive on queue batching…', 'body' => "The latest from the Laravel ecosystem:\n\n- Five Livewire 4 patterns you should be using\n- Deep dive: queue batching and failure handling\n- Package of the week: a tiny feature-flag library\n- Upcoming: Laracon schedule announced\n\nHappy coding!", 'time' => 'Mon', 'unread' => false, 'starred' => false, 'label' => 'News'],
            ['id' => 7, 'folder' => 'sent', 'from' => 'Me', 'email' => 'me@acme.com', 'subject' => 'Re: Q3 roadmap review — first pass comments', 'preview' => 'Left comments on slides 4 and 9. Overall this is in great shape — my only real concern is the mobile beta timing…', 'body' => "Left comments on slides 4 and 9.\n\nOverall this is in great shape — my only real concern is the mobile beta timing. If App Store review takes the full two weeks we'll be announcing before users can install.\n\nSuggest we pad it by one more week.", 'time' => 'Mon', 'unread' => false, 'starred' => false, 'label' => 'Work'],
            ['id' => 8, 'folder' => 'drafts', 'from' => 'Me', 'email' => 'me@acme.com', 'subject' => '(no subject)', 'preview' => 'Hey Priya, following up on the net-45 question — I checked with finance and…', 'body' => 'Hey Priya, following up on the net-45 question — I checked with finance and', 'time' => 'Sun', 'unread' => false, 'starred' => false, 'label' => 'Sales'],
            ['id' => 9, 'folder' => 'archive', 'from' => 'HR Team', 'email' => 'hr@acme.com', 'subject' => 'Benefits enrollment closes Friday', 'preview' => 'Reminder: open enrollment for 2027 benefits closes this Friday at 5 PM. No changes are needed if you want to keep…', 'body' => "Reminder: open enrollment for 2027 benefits closes this Friday at 5 PM.\n\nNo changes are needed if you want to keep your current elections. To make changes, log in to the benefits portal before the deadline.", 'time' => 'Aug 2', 'unread' => false, 'starred' => false, 'label' => null],
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getFilteredProperty(): Collection
    {
        return collect($this->messages)
            ->filter(fn (array $m): bool => $this->folder === 'starred' ? $m['starred'] : $m['folder'] === $this->folder)
            ->when($this->search !== '', fn (Collection $c) => $c->filter(
                fn (array $m): bool => str_contains(mb_strtolower($m['from'].' '.$m['subject'].' '.$m['preview']), mb_strtolower($this->search))
            ))
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function getSelectedProperty(): ?array
    {
        return collect($this->messages)->firstWhere('id', $this->selectedId);
    }

    public function counts(string $folder): int
    {
        if ($folder === 'starred') {
            return collect($this->messages)->where('starred', true)->count();
        }

        return collect($this->messages)->where('folder', $folder)->count();
    }

    public function unreadCount(): int
    {
        return collect($this->messages)->where('folder', 'inbox')->where('unread', true)->count();
    }

    public function setFolder(string $folder): void
    {
        $this->folder = $folder;
        $this->selectedId = $this->filtered->first()['id'] ?? null;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;

        foreach ($this->messages as &$m) {
            if ($m['id'] === $id) {
                $m['unread'] = false;
            }
        }
    }

    public function toggleStar(int $id): void
    {
        foreach ($this->messages as &$m) {
            if ($m['id'] === $id) {
                $m['starred'] = ! $m['starred'];
            }
        }
    }

    public function moveTo(int $id, string $folder): void
    {
        foreach ($this->messages as &$m) {
            if ($m['id'] === $id) {
                $m['folder'] = $folder;
            }
        }

        if ($this->selectedId === $id) {
            $this->selectedId = $this->filtered->first()['id'] ?? null;
        }

        Flux::toast(
            text: $folder === 'trash' ? 'Conversation moved to Trash.' : 'Conversation archived.',
            variant: 'success',
        );
    }

    public string $replyBody = '';

    public function openReply(): void
    {
        if ($this->selected === null) {
            return;
        }

        $this->replyBody = '';

        Flux::modal('reply')->show();
    }

    public function sendReply(): void
    {
        $this->validate(['replyBody' => 'required']);

        $this->reset('replyBody');

        Flux::modal('reply')->close();
        Flux::toast(heading: 'Reply sent', text: 'Your reply is on its way.', variant: 'success');
    }

    public function send(): void
    {
        $this->validate(['to' => 'required|email', 'subject' => 'required', 'body' => 'required']);

        $this->reset('to', 'subject', 'body');

        Flux::modal('compose')->close();
        Flux::toast(heading: 'Message sent', text: 'Your message is on its way.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.demo.email-inbox');
    }
}
