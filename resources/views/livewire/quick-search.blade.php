<form wire:submit="submit" class="quick-search" role="search">
    <div role="group" aria-label="{{ __('gshome.quick_search.mode') }}">
        <label><input type="radio" wire:model.live="mode" value="fresh"> {{ __('gshome.quick_search.mode_fresh') }}</label>
        <label><input type="radio" wire:model.live="mode" value="base"> {{ __('gshome.quick_search.mode_base') }}</label>
    </div>

    @if ($dealOptions !== [])
        <label>{{ __('gshome.search.deal') }}
            <select wire:model="deal" name="deal">
                @foreach ($dealOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <label>{{ __('gshome.listings.property_type') }}
        <select wire:model="property_type_id" name="property_type_id">
            <option value="">{{ __('gshome.listings.all') }}</option>
            @foreach ($types as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>{{ __('gshome.listings.city') }}
        <select wire:model.live="city_id" name="city_id">
            <option value="">{{ __('gshome.listings.all') }}</option>
            @foreach ($cities as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>{{ __('gshome.listings.district') }}
        <select wire:model="district_id" name="district_id" multiple>
            @foreach ($districts as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label>{{ __('gshome.listings.price_min') }} <input type="number" min="0" wire:model="price_usd_min" name="price_usd_min"></label>
    <label>{{ __('gshome.listings.price_max') }} <input type="number" min="0" wire:model="price_usd_max" name="price_usd_max"></label>
    <label>{{ __('gshome.listings.area_min') }} <input type="number" min="0" wire:model="area_min" name="area_min"></label>
    <label>{{ __('gshome.listings.area_max') }} <input type="number" min="0" wire:model="area_max" name="area_max"></label>

    <fieldset>
        <legend>{{ __('gshome.quick_search.bedrooms') }}</legend>
        @foreach (['1', '2', '3', '4', '5'] as $n)
            <label><input type="checkbox" wire:model="bedrooms" value="{{ $n }}"> {{ $n === '5' ? '5+' : $n }}</label>
        @endforeach
    </fieldset>

    @if ($collectorsOffline)
        <div role="alert">
            <p>{{ __('gshome.quick_search.collectors_offline') }}</p>
            <button type="button" wire:click="useBase">{{ __('gshome.quick_search.use_base') }}</button>
        </div>
    @endif

    @if ($mode === 'fresh' && $this->ssWillSkip())
        <p>{{ __('gshome.quick_search.ss_skip') }}</p>
    @endif

    <button type="submit" @disabled(! $this->canSubmit())>
        {{ $mode === 'base' ? __('gshome.quick_search.submit_base') : __('gshome.quick_search.submit_fresh') }}
    </button>
</form>
