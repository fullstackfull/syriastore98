@extends('front_end.layouts.app')

@section('page-title')
{{ __('Support Ticket') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['support_ticket_banner_status'] && $themeSettings['support_ticket_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['support_ticket_banner_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['support_ticket_banner_title'] ?? __('Support Ticket') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    
    <section class="lg:py-20 py-10">
       <div class="md:container w-full mx-auto px-4">
           <div class="flex flex-col lg:flex-row lg:gap-8 gap-6">
               @include('front_end.common.account-tab')

               @if ($themeSettings['support_ticket_list_status'] && $themeSettings['support_ticket_list_status'] == '1')
                <!-- Main Content -->
                <div class="lg:w-3/4">
                    <!-- Recent Orders -->
                    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
                        <div class="flex items-center justify-between md:mb-6 mb-4 gap-4 flex-wrap">
                            <h2 class="font-heading font-bold text-xl">{{ $themeSettings['support_ticket_list_title'] ?? __('Support Ticket') }}</h2>

                            <button class="btn-primary" data-ajax-popup="true" data-size="xl" data-title="{{ __('Create Support Ticket') }}" data-url="{{ route('add.support.ticket', $slug) }}" data-toggle="tooltip" title="{{ __('Add Ticket') }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4">
                                        <path d="M5 12h14" />
                                        <path d="M12 5v14" />
                                    </svg>
                                    {{ $themeSettings['support_ticket_list_button'] ?? __('Add Ticket') }}
                                </button>
                        </div>

                        <!-- Orders Table -->
                        <div class="overflow-x-auto">
                            <table class="md:w-full min-w-[570px]">
                                <thead class="text-left rtl:text-right bg-primary/10 border border-b">
                                    <tr>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Title') }}</th>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Ticket Id') }}</th>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Order Id') }}</th>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Customer') }}</th>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Status') }}</th>
                                        <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    @foreach ($tickets as $ticket)
                                        @php 
                                            $order = \App\Models\Order::find($ticket->order_id);
                                            $order_data = $order->order_detail($order->id);
                                        @endphp
                                        <tr>
                                            <td class="py-3 px-4 text-sm">{{ $ticket->title }}</td>
                                            <td class="py-3 px-4 text-sm">#{{ $ticket->ticket_id }}</td>
                                            <td class="py-3 px-4 text-sm">{{ !empty($order_data['order_id']) ? $order_data['order_id'] : '-' }}</td>
                                            <td class="py-3 px-4 text-sm font-medium">{{ optional($ticket->UserData)->name ?? '-' }}</td>
                                            <td class="py-3 px-4">
                                                <span class="inline-block bg-green-100 text-green-600 px-2 py-1 rounded border border-green-600 text-xs font-medium">
                                                    {{ $ticket->status }}
                                                </span>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="flex gap-2">
                                                        <button class="btn px-2 py-1 btn-primary"
                                                            data-url="{{ route('get.support.ticket',[$slug,$ticket->id]) }}" data-size="lg"
                                                            data-ajax-popup="true" data-title="{{ __('Edit Ticket') }}">
                                                            <i class="fas fa-pencil text-white py-1"></i>
                                                        </button>
                                                        <button class="btn px-2 py-1 btn-primary"
                                                            data-url="{{ route('reply.support.ticket',[$slug,$ticket->id]) }}" data-size="lg"
                                                            data-ajax-popup="true" data-title="{{ __('Reply Ticket') }}">
                                                            <i class="fas fa-share"></i>
                                                        </button>
                                                        {!! Form::open(['method' => 'GET', 'route' => ['destroy.ticket', $slug, $ticket->id], 'class' => 'd-inline']) !!}
                                                            <button type="submit" class="btn px-2 py-1 btn-primary text-danger">
                                                                <i class="fas fa-trash text-white py-1 " data-id="{{ $ticket->id }}" data-bs-toggle="tooltip" title="delete"></i>
                                                            </button>
                                                        {!! Form::close() !!}
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>

                                <!-- Pagination -->
                                <div class="flex justify-center md:mt-8 mt-5 pagination-wrapper">
                                    <div class="flex items-center gap-2">
                                        {{-- Previous Button --}}
                                        @if($tickets->onFirstPage())
                                            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="m15 18-6-6 6-6" />
                                                </svg>
                                            </span>
                                        @else
                                            <a href="{{ $tickets->previousPageUrl() }}"
                                                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="m15 18-6-6 6-6" />
                                                </svg>
                                            </a>
                                        @endif

                                        @php
                                            $current = $tickets->currentPage();
                                            $last = $tickets->lastPage();
                                        @endphp

                                        @if($current <= 2)
                                            {{-- Show first 3 pages: 1 2 3 ... 15 --}}
                                            @for($i = 1; $i <= min(3, $last); $i++)
                                                @if($i == $current)
                                                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                                @else
                                                    <a href="{{ $tickets->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                                @endif
                                            @endfor
                                            @if($last > 3)
                                                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                                <a href="{{ $tickets->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
                                            @endif
                                        @elseif($current >= $last - 1)
                                            {{-- Show last 3 pages: 1 ... 13 14 15 --}}
                                            <a href="{{ $tickets->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
                                            @if($last > 3)
                                                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                            @endif
                                            @for($i = max(1, $last - 2); $i <= $last; $i++)
                                                @if($i == $current)
                                                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                                @else
                                                    <a href="{{ $tickets->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                                @endif
                                            @endfor
                                        @else
                                            {{-- Show middle pages: 1 ... 7 8 ... 15 --}}
                                            <a href="{{ $tickets->url(1) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
                                            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                            @for($i = $current; $i <= $current + 1; $i++)
                                                @if($i == $current)
                                                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white">{{ $i }}</span>
                                                @else
                                                    <a href="{{ $tickets->url($i) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $i }}</a>
                                                @endif
                                            @endfor
                                            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                                            <a href="{{ $tickets->url($last) }}" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">{{ $last }}</a>
                                        @endif

                                        {{-- Next Button --}}
                                        @if($tickets->hasMorePages())
                                            <a href="{{ $tickets->nextPageUrl() }}"
                                                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="m9 18 6-6-6-6" />
                                                </svg>
                                            </a>
                                        @else
                                            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                    stroke-linejoin="round" class="h-4 w-4">
                                                    <path d="m9 18 6-6-6-6" />
                                                </svg>
                                            </span>
                                        @endif
                                    </div>
                                </div>
                        </div>
                    </div>
                </div>
               @endif
           </div>
       </div>
   </section>
    
  </main>
@endsection