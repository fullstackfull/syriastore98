@extends('front_end.layouts.app')

@section('page-title')
    {{ __('Privacy Policy') }}
@endsection

@section('content')
    <main>
        @if ($themeSettings['policy_banner_status'] && $themeSettings['policy_banner_status'] == '1')
            <!-- Common Banner Section -->
            <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
                style="background-image: url('{{ get_file($themeSettings['policy_banner_image'] ?? '') }}');">
                <div class="md:container w-full mx-auto px-4">
                    <div class="text-center relative z-[2]">
                        <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                            {{ $themeSettings['policy_banner_title'] ?? __('Privacy Policy') }}
                        </h2>
                    </div>
                </div>
            </section>
        @endif


        @if ($themeSettings['privacy_policy_status'] && $themeSettings['privacy_policy_status'] == '1')
        
                    <section class="py-12 md:py-20">
                        <div class="md:container w-full mx-auto px-4 md:px-6">
                            <div class="flex flex-col lg:flex-row gap-8">
                                <div class="bg-gray-50 border p-4 md:p-8 rounded-lg shadow-sm">
                                    <div class="flex items-center justify-between mb-6">
                                        <div>
                                            <p class="text-gray-500"><i class="fas fa-calendar-alt ltr:mr-2 rtl:ml-2"></i>{{__('Last Updated') }}: 
                                                {{ $themeSettings['privacy_policy_create_date'] ?? date('Y-m-d') }}
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        class="prose prose-lg max-w-none prose-headings:font-semibold prose-headings:text-gray-800 prose-p:text-gray-600 prose-li:text-gray-600">

                                        <div class="ltr:border-l-4 rtl:border-r-4 border-primary p-4 mb-6 rounded-lg transition-all bg-primary/10">
                                            <p class="font-medium text-gray-700">
                                                <i class="fas fa-info-circle text-primary mr-2"></i>
                                                {!! $themeSettings['privacy_policy_note'] ?? __('This Privacy Policy explains how we collect, use, and protect your personal information.') !!}
                                            </p>
                                        </div>
                                        @if (isset($themeSettings['privacy_policy_repeater']))
                                        @foreach (json_decode($themeSettings['privacy_policy_repeater']) as $index => $section)
                                            <h2 id="section-{{ $index + 1 }}" class="font-bold text-xl md:text-3xl mb-5">
                                                {{ $index + 1 }}. {{ $section->title ?? 'Untitled' }}
                                            </h2>
                                            {!! $section->summernote ?? '' !!}
                                            <br>
                                        @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
        @endif
    </main>
@endsection