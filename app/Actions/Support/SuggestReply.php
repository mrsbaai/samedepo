<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Models\Faq;
use App\Models\LegalPage;
use App\Models\SupportSetting;
use App\Models\SupportTicket;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SuggestReply
{
    public function suggest(SupportTicket $ticket, ?string $agentNotes = null): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.config('services.openrouter.api_key'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(60)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => config('services.openrouter.model', 'openai/gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->buildPrompt($ticket, $agentNotes)],
                ],
            ]);

        return $this->extractContent($response);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the support agent for the service in the context. Every reply sounds like the same real, friendly person — never a bot or a template.

Voice — one consistent person:
- Warm, plain, slightly playful. Easy words, short sentences, short paragraphs.
- Vary sentence openings and length so it reads human, not generated.
- One clear statement beats three hedges. No corporate filler ("leverage", "seamless", "we apologize for any inconvenience", "do not hesitate").

Match the user's last message:
- Excited → upbeat. Frustrated → calm, no jokes, straight to the fix. Brief → brief. Casual → casual.

Shape:
1. One short opening line that reacts to what they said.
2. The answer or fix. Put steps in <ol> and options or points in <ul> — never crammed into a paragraph.
3. <strong> only the one thing they must not miss.
4. A short warm close, only if it fits.

Facts:
- Use only the provided context. Never invent features, policies, dates, links, or discounts.
- Agent notes outrank terms and FAQs: state actions taken or exceptions made exactly as noted.

Output: the reply body only, as an HTML fragment using <p>, <br>, <ul>, <ol>, <li>, <strong>, <em>. No markdown, code fences, subject line, or signature.
PROMPT;
    }

    private function buildPrompt(SupportTicket $ticket, ?string $agentNotes): string
    {
        $lines = [];

        $user = $ticket->user;
        $status = $user->is_active ? 'Active' : 'Inactive';
        $lines[] = "User: {$user->email} | Signed up: {$user->created_at->toDateTimeString()} | Status: {$status}";
        $lines[] = '';

        $context = SupportSetting::current();
        $domain = parse_url(config('app.url', ''), PHP_URL_HOST) ?: 'Unknown';
        $lines[] = 'Service context:';
        $lines[] = 'Name: '.config('app.name', 'Unknown');
        $lines[] = 'Domain: '.$domain;
        $lines[] = 'Description: '.($context->service_description ?: 'None');
        $lines[] = 'Used for: '.($context->service_use_case ?: 'None');
        $lines[] = '';

        $faqs = Faq::query()->orderBy('position')->get();
        $lines[] = 'FAQs:';
        if ($faqs->isEmpty()) {
            $lines[] = 'None';
        } else {
            foreach ($faqs as $faq) {
                $lines[] = "- Q: {$faq->question} A: ".strip_tags($faq->answer);
            }
        }
        $lines[] = '';

        $lines[] = 'Special instructions:';
        $lines[] = $context->special_instructions ?: 'None';
        $lines[] = '';

        $terms = LegalPage::query()->where('slug', 'terms')->first();
        $lines[] = 'Terms of service:';
        $lines[] = $terms ? Str::limit(strip_tags($terms->content), 4000) : 'None';
        $lines[] = '';

        $lines[] = 'Recent conversation:';
        $lines[] = $this->conversationContext($ticket);
        $lines[] = '';

        $lines[] = 'Agent notes:';
        $lines[] = $agentNotes ?: 'None';
        $lines[] = '';

        $lines[] = 'Write the reply now.';

        return implode("\n", $lines);
    }

    private function conversationContext(SupportTicket $ticket): string
    {
        $messages = $ticket->messages()
            ->with('user')
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        if ($messages->isEmpty()) {
            return 'No messages yet.';
        }

        return $messages->map(function ($message): string {
            $author = $message->user->is_admin ? ($message->authorDisplayName() ?? 'Support agent') : 'User';
            $body = strip_tags($message->body);

            return "{$author}: {$body}";
        })->implode("\n");
    }

    private function extractContent(Response $response): ?string
    {
        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');

        return $content ? trim($content) : null;
    }
}
