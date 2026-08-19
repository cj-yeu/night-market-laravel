@props(['operatingDays'])

@if ($operatingDays->isEmpty())
    <p {{ $attributes->class(['text-secondary mb-0']) }}>Operating schedule not available.</p>
@else
    <div {{ $attributes->class(['vstack gap-2']) }}>
        @foreach ($operatingDays as $operatingDay)
            <div class="d-flex flex-wrap justify-content-between gap-2 border-bottom pb-2">
                <span class="badge text-bg-light border align-self-start">{{ $operatingDay->day_of_week }}</span>
                <span class="small text-secondary">
                    @if ($operatingDay->opening_time && $operatingDay->closing_time)
                        {{ $operatingDay->opening_time->format('g:i A') }}–{{ $operatingDay->closing_time->format('g:i A') }}
                    @elseif ($operatingDay->opening_time)
                        Opens {{ $operatingDay->opening_time->format('g:i A') }}; closing time not available
                    @elseif ($operatingDay->closing_time)
                        Closes {{ $operatingDay->closing_time->format('g:i A') }}; opening time not available
                    @else
                        Time not available
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endif
