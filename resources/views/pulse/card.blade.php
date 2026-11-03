<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="SlackCache"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.circle-stack />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-4">
            <div class="flex flex-col justify-center @sm:block">
                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($rateLimited->sum('count')) }}</span>
                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Rate limited ({{ number_format($rateLimited->sum('sum')) }}s waited)</span>
            </div>
            <div class="flex flex-col justify-center @sm:block">
                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($retries->sum('count')) }}</span>
                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Retries</span>
            </div>
            <div class="flex flex-col justify-center @sm:block">
                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($failures->sum('count')) }}</span>
                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Errors</span>
            </div>
            <div class="flex flex-col justify-center @sm:block">
                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">{{ number_format($reloads->sum('count')) }}</span>
                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">Reloads (max {{ number_format((float) $reloads->max('max')) }} ms)</span>
            </div>
        </div>

        @if ($calls->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Method</x-pulse::th>
                        <x-pulse::th class="text-right">Calls</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                        <x-pulse::th class="text-right">Slowest</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($calls as $call)
                        <tr wire:key="{{ $call->key }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $call->key }}-row">
                            <x-pulse::td><code class="block text-xs text-gray-900 dark:text-gray-100 truncate">{{ $call->key }}</code></x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">{{ number_format($call->count) }}</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format((float) $call->avg) }} ms</x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">{{ number_format((float) $call->max) }} ms</x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>

            @if ($failures->isNotEmpty())
                <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($failures as $error)
                        <div><code>{{ $error->key }}</code> × {{ number_format($error->count) }}</div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-pulse::scroll>
</x-pulse::card>
