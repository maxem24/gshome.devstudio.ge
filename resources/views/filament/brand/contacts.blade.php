<div class="py-4 text-center text-xs text-gray-500 dark:text-gray-400">
    <strong>JS Home</strong> · {{ config('branding.tagline') }}
    @foreach (config('branding.phones') as $phone)
        ·
        <a href="tel:{{ $phone['tel'] }}">{{ $phone['display'] }}</a>
    @endforeach
</div>
