@php
    $admin_settings = getSuperAdminAllSetting();
    $company_settings = getAdminAllSetting();
    $favicon = isset($company_settings['favicon'])
        ? $company_settings['favicon']
        : (isset($admin_settings['favicon'])
            ? $admin_settings['favicon']
            : 'storage/uploads/logo/favicon.png');
    $logo = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::GetLogo();
    $company_logo = get_file($company_logo);
@endphp
<!DOCTYPE html>
<html lang="en" dir="{{ isset($company_settings['SITE_RTL']) && $company_settings['SITE_RTL'] == 'on' ? 'rtl' : '' }}">

<head>
    <title>{{ config('app.name', 'Laravel') }} | {{ __('API Documentation') }}</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <link rel="icon" href="{{ check_file($favicon) ? get_file($favicon) : get_file('storage/uploads/logo/favicon.png') }}{{ '?' . time() }}" type="image/x-icon" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">

    <!-- Styles -->
    @if ((isset($company_settings['SITE_RTL']) ? $company_settings['SITE_RTL'] : 'off') == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    @endif

    <style>
        :root {
            --primary-color: #10B77F;
            --primary-dark: #2563eb;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-color: #1f2937;
            --light-color: #f8fafc;
            --border-color: #e5e7eb;
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #10B77F 0%, #10B77F 100%);
            min-height: 100vh;
        }

        .api-container {
            display: flex;
            min-height: 100vh;
        }

        .api-sidebar {
            width: 320px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }

        .api-content {
            flex: 1;
            margin-left: 320px;
            background: white;
            min-height: 100vh;
        }

        .api-header {
            padding: 2rem;
            background: transparent;
            color: white;
            text-align: center;
        }

        .api-logo {
            max-width: 180px;
            height: auto;
            margin-bottom: 1rem;
        }

        .api-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }

        .api-nav {
            padding: 1rem 0;
        }

        .nav-section {
            margin-bottom: 0.5rem;
        }

        .nav-title {
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            background: var(--light-color);
        }

        .nav-item {
            display: block;
            padding: 0.75rem 1.5rem;
            color: #6b7280;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover {
            background: var(--light-color);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            text-decoration: none;
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            border-left-color: var(--primary-color);
            font-weight: 500;
        }

        .nav-submenu {
            background: #f9fafb;
        }

        .nav-submenu .nav-item {
            padding-left: 3rem;
            font-size: 0.875rem;
        }

        .method-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-right: 0.5rem;
        }

        .method-get { background: #dbeafe; color: #1d4ed8; }
        .method-post { background: #dcfce7; color: #166534; }
        .method-put { background: #fef3c7; color: #92400e; }
        .method-delete { background: #fee2e2; color: #991b1b; }

        .content-section {
            padding: 3rem;
            max-width: 1200px;
        }

        .section-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 1rem;
        }

        .section-subtitle {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin: 2rem 0 1rem 0;
        }

        .section-description {
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .endpoint-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 3rem;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .endpoint-header {
            padding: 1.5rem;
            background: var(--light-color);
            border-bottom: 1px solid var(--border-color);
        }

        .endpoint-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 0.5rem;
        }

        .endpoint-url {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-family: 'Monaco', 'Menlo', monospace;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .endpoint-body {
            padding: 1.5rem;
        }

        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 1.5rem;
            border-radius: 8px;
            overflow-x: auto;
            margin: 1rem 0;
            position: relative;
        }

        .code-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #374151;
        }

        .code-title {
            font-weight: 600;
            color: #f9fafb;
        }

        .copy-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }

        .copy-btn:hover {
            background: var(--primary-dark);
        }

        .response-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1rem;
        }

        .response-tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #6b7280;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .response-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .response-content {
            display: none;
        }

        .response-content.active {
            display: block;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }

        .status-success {
            background: #dcfce7;
            color: #166534;
        }

        .status-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .mobile-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.75rem;
            border-radius: 8px;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .api-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .api-sidebar.open {
                transform: translateX(0);
            }

            .api-content {
                margin-left: 0;
            }

            .mobile-toggle {
                display: block;
            }

            .content-section {
                padding: 2rem 1rem;
            }
        }

        .intro-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin: 2rem 0;
        }

        @media (max-width: 768px) {
            .intro-grid {
                grid-template-columns: 1fr;
            }
        }

        .base-url-card {
            background: var(--dark-color);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            font-family: 'Monaco', 'Menlo', monospace;
        }

        .base-url-title {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #9ca3af;
        }
    </style>
</head>

<body>
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="ti ti-menu-2"></i>
    </button>

    <div class="api-container">
        <aside class="api-sidebar" id="sidebar">
            <div class="api-header">
                <img src="{{ isset($company_logo) && !empty($company_logo) ? $company_logo : $logo . '/logo-dark.svg' }}" alt="Logo" class="api-logo" />
                <h1 class="api-title">{{ __('API Documentation') }}</h1>
            </div>

            <nav class="api-nav">
                <div class="nav-section">
                    <div class="nav-title">{{ __('Getting Started') }}</div>
                    <a href="#introduction" class="nav-item active">{{ $data->section->title->text ?? __('Introduction') }}</a>
                </div>

                @foreach ($fileNames as $fileName)
                    <div class="nav-section">
                        <div class="nav-title">{{ ucFirst($fileName) }}</div>
                        @foreach ($contents[$fileName] as $category_name => $values)
                            <div class="nav-submenu">
                                @foreach ($values as $key => $value)
                                    <a href="#{{ $key }}" class="nav-item">
                                        <span class="method-badge method-{{ strtolower($value->method) }}">{{ $value->method }}</span>
                                        {{ ucFirst(str_replace('-', ' ', $key)) }}
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </nav>
        </aside>

        <main class="api-content">
            <div class="content-section" id="introduction">
                <h1 class="section-title">{{ $data->section->title->text ?? __('API Documentation') }}</h1>
                <p class="section-description">{{ $data->section->description->text ?? __('Welcome to our API documentation. Here you\'ll find everything you need to integrate with our services.') }}</p>

                <div class="intro-grid">
                    <div>
                        <h2 class="section-subtitle">{{ $data->section->subtitle->text ?? __('Getting Started') }}</h2>
                        <p class="section-description">{{ $data->section->subdescription->text ?? __('Follow these steps to get started with our API.') }}</p>

                        <h3 class="section-subtitle">{{ $data->section->endpoint->text ?? __('Base Endpoints') }}</h3>
                        @if (isset($data->section->endpoint->endpoint))
                            <ul>
                                @for ($i = 0; $i < $data->section->endpoint->loop_number ?? 1; $i++)
                                    <li>{{ $data->section->endpoint->endpoint[$i] ?? '' }}</li>
                                @endfor
                            </ul>
                        @endif
                        <p class="section-description">{{ $data->section->endpoint_description->text ?? '' }}</p>

                        <h3 class="section-subtitle">{{ $data->section->request_parameter_title->text ?? __('Authentication') }}</h3>
                        <p class="section-description">{{ $data->section->request_parameter_description->text ?? '' }}</p>
                        @if (isset($data->section->request_parameter_sub_data))
                            <ul>
                                @for ($i = 0; $i < $data->section->request_parameter_sub_data->loop_number ?? 1; $i++)
                                    <li>
                                        <strong>{{ $data->section->request_parameter_sub_data->sub_title[$i] ?? '' }}</strong>: 
                                        {{ $data->section->request_parameter_sub_data->sub_description[$i] ?? '' }}
                                    </li>
                                @endfor
                            </ul>
                        @endif
                    </div>

                    <div>
                        <div class="base-url-card">
                            <div class="base-url-title">{{ __('Base URL') }}</div>
                            <code>{{ url('/') }}/api</code>
                        </div>
                    </div>
                </div>
            </div>

            @foreach ($fileNames as $fileName)
                @foreach ($contents[$fileName] as $category_name => $values)
                    @foreach ($values as $key => $apiDetail)
                        <div class="content-section" id="{{ $key }}">
                            <div class="endpoint-card">
                                <div class="endpoint-header">
                                    <h2 class="endpoint-title">{{ ucFirst(str_replace('-', ' ', $key)) }}</h2>
                                    <div class="endpoint-url">
                                        <span class="method-badge method-{{ strtolower($apiDetail->method) }}">{{ $apiDetail->method }}</span>
                                        <code>{{ $apiDetail->endpoints }}</code>
                                        <button class="copy-btn" onclick="copyToClipboard('{{ $apiDetail->endpoints }}')">
                                            <i class="ti ti-copy"></i> {{ __('Copy') }}
                                        </button>
                                    </div>
                                </div>

                                <div class="endpoint-body">
                                    <h3 class="section-subtitle">{{ __('Headers') }}</h3>
                                    <div class="code-block">
                                        <div class="code-header">
                                            <span class="code-title">{{ __('Request Headers') }}</span>
                                            <button class="copy-btn" onclick="copyToClipboard({{ json_encode($apiDetail->header) }})">
                                                <i class="ti ti-copy"></i> {{ __('Copy') }}
                                            </button>
                                        </div>
                                        <pre><code>{{ json_encode($apiDetail->header, JSON_PRETTY_PRINT) }}</code></pre>
                                    </div>

                                    @if (!empty($apiDetail->request))
                                        <h3 class="section-subtitle">{{ __('Parameters') }}</h3>
                                        <div class="code-block">
                                            <div class="code-header">
                                                <span class="code-title">{{ __('Request Parameters') }}</span>
                                                <button class="copy-btn" onclick="copyToClipboard({{ json_encode($apiDetail->request) }})">
                                                    <i class="ti ti-copy"></i> {{ __('Copy') }}
                                                </button>
                                            </div>
                                            <pre><code>{{ json_encode($apiDetail->request, JSON_PRETTY_PRINT) }}</code></pre>
                                        </div>
                                    @endif

                                    <h3 class="section-subtitle">{{ __('Response') }}</h3>
                                    <div class="response-tabs">
                                        <button class="response-tab active" onclick="showResponse('success-{{ $key }}')">
                                            {{ __('Success') }}
                                        </button>
                                        <button class="response-tab" onclick="showResponse('error-{{ $key }}')">
                                            {{ __('Error') }}
                                        </button>
                                    </div>

                                    <div id="success-{{ $key }}" class="response-content active">
                                        <div class="status-badge status-success">{{ $apiDetail->status_code->success }}</div>
                                        <div class="code-block">
                                            <div class="code-header">
                                                <span class="code-title">{{ __('Success Response') }}</span>
                                                <button class="copy-btn" onclick="copyToClipboard({{ json_encode($apiDetail->success_response) }})">
                                                    <i class="ti ti-copy"></i> {{ __('Copy') }}
                                                </button>
                                            </div>
                                            <pre><code>{{ json_encode($apiDetail->success_response, JSON_PRETTY_PRINT) }}</code></pre>
                                        </div>
                                    </div>

                                    <div id="error-{{ $key }}" class="response-content">
                                        <div class="status-badge status-error">{{ $apiDetail->status_code->error }}</div>
                                        <div class="code-block">
                                            <div class="code-header">
                                                <span class="code-title">{{ __('Error Response') }}</span>
                                                <button class="copy-btn" onclick="copyToClipboard({{ json_encode($apiDetail->error_response) }})">
                                                    <i class="ti ti-copy"></i> {{ __('Copy') }}
                                                </button>
                                            </div>
                                            <pre><code>{{ json_encode($apiDetail->error_response, JSON_PRETTY_PRINT) }}</code></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            @endforeach
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }

        function showResponse(responseId) {
            // Hide all response contents
            document.querySelectorAll('.response-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.response-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected response content
            document.getElementById(responseId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        function copyToClipboard(text) {
            const textToCopy = typeof text === 'string' ? text : JSON.stringify(text, null, 2);
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    showCopyFeedback(event.target);
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showCopyFeedback(event.target);
            }
        }

        function showCopyFeedback(button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="ti ti-check"></i> {{ __("Copied") }}';
            button.style.background = '#10b981';
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.style.background = '';
            }, 2000);
        }

        // Smooth scrolling for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                    
                    // Update active nav item
                    document.querySelectorAll('.nav-item').forEach(item => {
                        item.classList.remove('active');
                    });
                    this.classList.add('active');
                }
            });
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });
    </script>
</body>
</html>