<div class="mx-auto max-w-3xl">
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mb-6">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($errors->has('verification'))
        <flux:callout variant="danger" icon="exclamation-circle" class="mb-6">
            <flux:callout.text>{{ $errors->first('verification') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:heading size="xl">Security</flux:heading>
    <flux:subheading class="mt-2">Manage your email address, password, and two-factor authentication.</flux:subheading>

    <flux:separator variant="subtle" class="my-8" />

    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <div class="w-80">
            <flux:heading size="lg">Email address</flux:heading>
            <flux:subheading>Change the email address associated with your account. A verification link is required before the new address takes effect.</flux:subheading>
        </div>

        <div class="flex-1 space-y-6">
            <livewire:authentication.change-email />
        </div>
    </div>

    <flux:separator variant="subtle" class="my-8" />

    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <div class="w-80">
            <flux:heading size="lg">Password</flux:heading>
            <flux:subheading>Update your password and sign out any other active sessions.</flux:subheading>
        </div>

        <div class="flex-1 space-y-6">
            <livewire:authentication.change-password />
        </div>
    </div>

    <flux:separator variant="subtle" class="my-8" />

    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6 pb-10">
        <div class="w-80">
            <flux:heading size="lg">Two-factor authentication</flux:heading>
            <flux:subheading>Add an extra layer of security by requiring a code from an authenticator app when you sign in.</flux:subheading>
        </div>

        <div class="flex-1 space-y-6">
            <livewire:authentication.two-factor-security />
        </div>
    </div>
</div>
