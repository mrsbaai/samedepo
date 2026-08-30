<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Support Tickets</flux:heading>
        <flux:subheading class="mt-2">Open tickets waiting for a reply.</flux:subheading>
    </div>

    @include('components.admin.open-tickets', ['tickets' => $tickets])
</div>
