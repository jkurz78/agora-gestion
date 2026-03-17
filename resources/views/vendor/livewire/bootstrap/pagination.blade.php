@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav class="d-flex justify-items-center justify-content-between">
            {{-- Mobile layout --}}
            <div class="d-flex justify-content-between flex-fill d-sm-none">
                <ul class="pagination">
                    {{-- First Page --}}
                    @if ($paginator->onFirstPage())
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">&laquo;</span>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="gotoPage(1, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    aria-label="@lang('pagination.first')">&laquo;</button>
                        </li>
                    @endif

                    {{-- Previous Page --}}
                    @if ($paginator->onFirstPage())
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link">@lang('pagination.previous')</span>
                        </li>
                    @else
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled">@lang('pagination.previous')</button>
                        </li>
                    @endif

                    {{-- Next Page --}}
                    @if ($paginator->hasMorePages())
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled">@lang('pagination.next')</button>
                        </li>
                    @else
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">@lang('pagination.next')</span>
                        </li>
                    @endif

                    {{-- Last Page --}}
                    @if ($paginator->hasMorePages())
                        <li class="page-item">
                            <button type="button" class="page-link"
                                    wire:click="gotoPage({{ $paginator->lastPage() }}, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    aria-label="@lang('pagination.last')">&raquo;</button>
                        </li>
                    @else
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link" aria-hidden="true">&raquo;</span>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Desktop layout --}}
            <div class="d-none flex-sm-fill d-sm-flex align-items-sm-center justify-content-sm-end">
                <div>
                    <ul class="pagination">
                        {{-- First Page --}}
                        @if ($paginator->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.first')">
                                <span class="page-link" aria-hidden="true">&laquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button" class="page-link"
                                        wire:click="gotoPage(1, '{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.first')">&laquo;</button>
                            </li>
                        @endif

                        {{-- Previous Page --}}
                        @if ($paginator->onFirstPage())
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">
                                <span class="page-link" aria-hidden="true">&lsaquo;</span>
                            </li>
                        @else
                            <li class="page-item">
                                <button type="button"
                                        dusk="previousPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}"
                                        class="page-link"
                                        wire:click="previousPage('{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.previous')">&lsaquo;</button>
                            </li>
                        @endif

                        {{-- Pagination Elements --}}
                        @foreach ($elements as $element)
                            {{-- "Three Dots" Separator --}}
                            @if (is_string($element))
                                <li class="page-item disabled" aria-disabled="true">
                                    <span class="page-link">{{ $element }}</span>
                                </li>
                            @endif

                            {{-- Array Of Links --}}
                            @if (is_array($element))
                                @foreach ($element as $page => $url)
                                    @if ($page == $paginator->currentPage())
                                        <li class="page-item active"
                                            wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}"
                                            aria-current="page">
                                            <span class="page-link">{{ $page }}</span>
                                        </li>
                                    @else
                                        <li class="page-item"
                                            wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}">
                                            <button type="button" class="page-link"
                                                    wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                                    x-on:click="{{ $scrollIntoViewJsSnippet }}">{{ $page }}</button>
                                        </li>
                                    @endif
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Next Page --}}
                        @if ($paginator->hasMorePages())
                            <li class="page-item">
                                <button type="button"
                                        dusk="nextPage{{ $paginator->getPageName() == 'page' ? '' : '.' . $paginator->getPageName() }}"
                                        class="page-link"
                                        wire:click="nextPage('{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.next')">&rsaquo;</button>
                            </li>
                        @else
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.next')">
                                <span class="page-link" aria-hidden="true">&rsaquo;</span>
                            </li>
                        @endif

                        {{-- Last Page --}}
                        @if ($paginator->hasMorePages())
                            <li class="page-item">
                                <button type="button" class="page-link"
                                        wire:click="gotoPage({{ $paginator->lastPage() }}, '{{ $paginator->getPageName() }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                        wire:loading.attr="disabled"
                                        aria-label="@lang('pagination.last')">&raquo;</button>
                            </li>
                        @else
                            <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.last')">
                                <span class="page-link" aria-hidden="true">&raquo;</span>
                            </li>
                        @endif
                    </ul>
                </div>
            </div>
        </nav>
    @endif
</div>
