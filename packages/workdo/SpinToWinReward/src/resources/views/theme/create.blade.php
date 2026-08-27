<style>
:root {
    --spin-primary: #6366f1;
    --spin-secondary: #8b5cf6;
    --spin-success: #10b981;
    --spin-warning: #f59e0b;
    --spin-danger: #ef4444;
    --spin-info: #06b6d4;
    --spin-light: #f8fafc;
    --spin-dark: #1e293b;
}

.spin-container {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
    border-radius: 30px;
    padding: 50px;
    box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.15);
    margin: 20px auto;
    max-width: 800px;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(20px);
}

.spin-container::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(255, 255, 255, 0.15) 0%, transparent 40%),
        radial-gradient(circle at 80% 70%, rgba(255, 255, 255, 0.1) 0%, transparent 40%);
    pointer-events: none;
}



.spin-header {
    text-align: center;
    margin-bottom: 30px;
}

.spin-title {
    color: white;
    font-size: 36px;
    font-weight: 900;
    margin-bottom: 15px;
    text-shadow: 0 6px 12px rgba(0, 0, 0, 0.4);
    letter-spacing: -1px;
    background: linear-gradient(135deg, #fff 0%, #f0f9ff 50%, #dbeafe 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    position: relative;
    z-index: 2;
}

.spin-subtitle {
    color: rgba(255, 255, 255, 0.9);
    font-size: 16px;
    margin: 0;
}

.spin-wheel-container {
    position: relative;
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 30px auto;
    width: 100%;
    height: 400px;
}

#spinwheel {
    position: relative;
    filter: drop-shadow(0 20px 40px rgba(0, 0, 0, 0.3));
    z-index: 2;
}



#spinwheel svg {
    width: 100%;
    height: 100%;
    border: 8px solid white;
    border-radius: 50%;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    box-shadow: 
        inset 0 0 30px rgba(0, 0, 0, 0.1),
        0 0 0 4px rgba(255, 255, 255, 0.5),
        0 0 0 8px rgba(255, 255, 255, 0.2);
}

.wheel-spin-arrow {
    position: absolute;
    top: 50%;
    right: 130px;
    transform: translateY(-50%) rotate(-90deg);
    z-index: 10;
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.4));
}

.wheel-spin-arrow svg {
    width: 40px;
    height: auto;
}

.wheel-spin-arrow svg path {
    fill: #10b981;
    filter: drop-shadow(0 2px 4px rgba(16, 185, 129, 0.3));
}

.spinbtn {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    border: 5px solid white;
    border-radius: 50%;
    width: 90px;
    height: 90px;
    font-size: 16px;
    font-weight: 900;
    cursor: pointer;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 15;
    transition: all 0.3s ease;
    box-shadow: 
        0 10px 30px rgba(99, 102, 241, 0.4),
        inset 0 2px 4px rgba(255, 255, 255, 0.2);
    text-transform: uppercase;
    letter-spacing: 1px;
}



.spinbtn:hover {
    transform: translate(-50%, -50%) scale(1.05);
    box-shadow: 
        0 15px 40px rgba(99, 102, 241, 0.6),
        inset 0 3px 6px rgba(255, 255, 255, 0.3);
    background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
}

.spinbtn:active {
    transform: translate(-50%, -50%) scale(1.05);
    transition: transform 0.1s ease;
}

.spinner-form {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 25px;
    padding: 40px;
    backdrop-filter: blur(20px);
    box-shadow: 
        0 20px 50px rgba(0, 0, 0, 0.15),
        0 0 0 1px rgba(255, 255, 255, 0.3),
        inset 0 1px 0 rgba(255, 255, 255, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.4);
    position: relative;
    z-index: 2;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    font-weight: 600;
    color: var(--spin-dark);
    margin-bottom: 8px;
    display: block;
}

.form-control {
    width: 100%;
    padding: 15px 20px;
    border: 2px solid #e2e8f0;
    border-radius: 15px;
    font-size: 15px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
    background: rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(10px);
}

.form-control:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
    outline: none;
    background: rgba(255, 255, 255, 0.95);
    transform: translateY(-2px);
}

.spinner-button {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    border: none;
    border-radius: 20px;
    padding: 20px 40px;
    color: white;
    font-weight: 800;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    width: 100%;
    text-transform: uppercase;
    letter-spacing: 2px;
    box-shadow: 
        0 10px 30px rgba(99, 102, 241, 0.4),
        inset 0 2px 4px rgba(255, 255, 255, 0.2);
    position: relative;
    overflow: hidden;
}

.spinner-button::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.6s ease;
}

.spinner-button:hover::before {
    left: 100%;
}

.spinner-button:hover {
    transform: translateY(-3px);
    box-shadow: 
        0 15px 40px rgba(99, 102, 241, 0.5),
        inset 0 3px 6px rgba(255, 255, 255, 0.3);
    background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
}

.result-container {
    text-align: center;
    padding: 40px;
    background: rgba(255, 255, 255, 0.98);
    border-radius: 20px;
    margin-top: 25px;
    backdrop-filter: blur(15px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    animation: slideUp 0.5s ease-out;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.creakers {
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .spin-container {
        padding: 25px;
        margin: 5px;
        border-radius: 20px;
    }
    
    #spinwheel {
        width: 300px;
        height: 300px;
    }
    
    .spin-title {
        font-size: 28px;
    }
    
    .spinbtn {
        width: 70px;
        height: 70px;
        font-size: 16px;
    }
    
    .spinner-form {
        padding: 25px;
    }
}

@media (max-width: 480px) {
    #spinwheel {
        width: 260px;
        height: 260px;
    }
    
    .spin-container {
        padding: 20px;
    }
    
    .spin-title {
        font-size: 24px;
    }
    
    .spinbtn {
        width: 60px;
        height: 60px;
        font-size: 14px;
    }
    
    .spinner-form {
        padding: 20px;
    }
}
</style>

<div class="spin-container">
    <div class="spin-header">
        <h2 class="spin-title">🎯 {{ __('Spin to Win!') }}</h2>
        <p class="spin-subtitle">{{ __('Try your luck and win amazing prizes') }}</p>
    </div>

    @if (auth('customers')->user())
        <div class="spinner-final-view">
            <div class="spin-wheel-container">
                <div id="spinwheel">
                    <button id="spin" class="spinbtn">{{ __('Spin') }}</button>
                    <div class="spinnerwheel"></div>
                </div>
                <div id="spin-arrow" class="wheel-spin-arrow">
                    <svg width="83" height="74" viewBox="0 0 83 74" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M32.9489 5.12466C33.8289 3.59888 35.0943 2.3319 36.618 1.45104C38.1417 0.570174 39.8701 0.106445 41.6294 0.106445C43.3888 0.106445 45.1171 0.570174 46.6409 1.45104C48.1646 2.3319 49.43 3.59888 50.31 5.12466L80.9178 58.1922C81.7993 59.7185 82.264 61.4504 82.265 63.2137C82.2659 64.9769 81.8032 66.7094 80.9234 68.2366C80.0435 69.7639 78.7776 71.0322 77.2529 71.9139C75.7282 72.7955 73.9986 73.2595 72.238 73.2591H11.0223C9.26269 73.259 7.53405 72.7951 6.01016 71.9139C4.48627 71.0327 3.22083 69.7653 2.34102 68.2391C1.46121 66.7128 0.998036 64.9815 0.998047 63.2192C0.998058 61.4569 1.46125 59.7256 2.34108 58.1994L32.9489 5.12466Z" fill="currentColor"/>
                    </svg>
                </div>
            </div>
            <p class="spin-subtitle">{{ __('Click SPIN to try your luck!') }}</p>
        </div>
    @else
        <div class="spinner-view">
            <div class="spinner-form">
                <div class="form-group">
                    <label class="form-label">{{ __('Name') }}</label>
                    <input type="text" class="form-control spin-name" placeholder="{{ __('Enter Your Name') }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">{{ __('Email Address') }}</label>
                    <input type="email" class="form-control spin-email" placeholder="{{ __('Enter your email') }}" required>
                </div>
                <input type="hidden" name="is_used" value="no">
                <div class="form-group">
                    <label class="form-label">{{ __('Contact No.') }}</label>
                    <input type="tel" class="form-control spin-number" placeholder="{{ __('Enter your phone number') }}" required>
                </div>
                <button type="submit" class="spinner-button">{{ __('Start Spinning') }}</button>
            </div>
        </div>
    @endif
</div>

<script src="https://d3js.org/d3.v3.min.js"></script>
<script>
var authCustomer = @json($customer);

// Wait for d3 to load and modal to be visible
function waitForD3AndInitialize() {
    if (typeof d3 !== 'undefined' && $('#spinwheel').length > 0) {
        initializeSpinWheel();
    } else {
        setTimeout(waitForD3AndInitialize, 100);
    }
}

if (authCustomer) {
    $(document).ready(function() {
        waitForD3AndInitialize();
    });
}

function initializeSpinWheel() {
    if (typeof d3 === 'undefined') {
        console.error('D3.js not loaded');
        return;
    }
    var padding = { top: 0, right: 0, bottom: 0, left: 0 };
    var w = 320 - padding.left - padding.right;
    var h = 320 - padding.top - padding.bottom;
    var r = Math.min(w, h) / 2;
    var rotation = 0;
    var oldrotation = 0;
    var picked = 100000;
    var oldpick = [];
    
    // Define vibrant colors for wheel segments
    var colors = [
        '#6366f1', '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', '#06b6d4',
        '#ec4899', '#84cc16', '#f97316', '#3b82f6', '#a855f7', '#14b8a6',
        '#f43f5e', '#22c55e', '#eab308', '#8b5cf6', '#06b6d4', '#f59e0b'
    ];

    var labels = {!! $labelsJson !!};
    var data = labels.map(function(label) {
        return { "label": label, "value": 1, "xp": label };
    });

    var svg = d3.select('#spinwheel')
        .append("svg")
        .data([data])
        .attr("width", w)
        .attr("height", h);

    var container = svg.append("g")
        .attr("class", "chartholder")
        .attr("transform", "translate(" + (w/2) + "," + (h/2) + ")");
    
    var vis = container.append("g");

    var pie = d3.layout.pie().sort(null).value(function(d) { return 1; });
    var arc = d3.svg.arc().outerRadius(r);

    var arcs = vis.selectAll("g.slice")
        .data(pie(data))
        .enter()
        .append("g")
        .attr("class", "slice");

    arcs.append("path")
        .attr("fill", function(d, i) { return colors[i % colors.length]; })
        .attr("d", arc);

    arcs.append("text")
        .attr("transform", function(d) {
            d.innerRadius = 0;
            d.outerRadius = r;
            d.angle = (d.startAngle + d.endAngle) / 2;
            return "rotate(" + (d.angle * 180 / Math.PI - 90) + ")translate(" + (d.outerRadius - 10) + ")";
        })
        .attr("text-anchor", "end")
        .attr("font-size", "12px")
        .attr("fill", "white")
        .attr("font-weight", "bold")
        .text(function(d, i) { return data[i].label; });

    container.append("circle")
        .attr("cx", 0)
        .attr("cy", 0)
        .attr("r", 35)
        .style("fill", "white");

    $('#spin').on("click", function() {
        if (oldpick.length === data.length) return;

        $(this).off("click");
        var ps = 360 / data.length;
        var rng = Math.floor((Math.random() * 1440) + 360);
        rotation = (Math.round(rng / ps) * ps);
        picked = Math.round(data.length - (rotation % 360) / ps);
        picked = picked >= data.length ? (picked % data.length) : picked;

        if (oldpick.indexOf(picked) !== -1) {
            $(this).trigger('click');
            return;
        }
        oldpick.push(picked);
        rotation += 90 - Math.round(ps / 2);

        vis.transition()
            .duration(3000)
            .attrTween("transform", function() {
                var i = d3.interpolate(oldrotation % 360, rotation);
                return function(t) { return "rotate(" + i(t) + ")"; };
            })
            .each("end", function() {
                var winnerData = {
                    xp: data[picked].xp,
                    name: authCustomer ? (authCustomer.first_name + ' ' + (authCustomer.last_name || '')) : formData.name || '',
                    email: authCustomer ? authCustomer.email : formData.email || '',
                    number: authCustomer ? authCustomer.mobile : formData.number || ''
                };
                
                $.ajax({
                    url: "{{ route('manage-customer-data', $store->slug) }}",
                    method: 'POST',
                    data: winnerData,
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                    success: function(response) {
                        var resultHtml = '<div class="result-container">';
                        if (response.status == 'success') {
                            resultHtml += '<div class="creakers"><img src="{{ asset('packages/workdo/SpinToWinReward/src/marketplace/creackers.gif') }}"></div>';
                            resultHtml += '<h3 style="color: #10b981;">🎉 ' + response.message + '</h3>';
                        } else {
                            resultHtml += '<h3 style="color: #f59e0b;">😔 ' + response.message + '</h3>';
                        }
                        resultHtml += '</div>';
                        $('.spinner-view').html(resultHtml);
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', xhr.responseText);
                        var resultHtml = '<div class="result-container">';
                        resultHtml += '<h3 style="color: #ef4444;">❌ An error occurred. Please try again.</h3>';
                        resultHtml += '</div>';
                        $('.spinner-view').html(resultHtml);
                    }
                });
                oldrotation = rotation;
            });
    });
}

// Store form data globally
var formData = {
    name: '',
    email: '',
    number: ''
};

$(".spinner-button").click(function(e) {
    e.preventDefault();
    
    // Store form data before submitting
    formData.name = $('.spin-name').val();
    formData.email = $('.spin-email').val();
    formData.number = $('.spin-number').val();
    
    var data = {
        name: formData.name,
        email: formData.email,
        number: formData.number,
        is_used: 'no'
    };

    $.ajax({
        url: '{{ route('spintowinreward.store', $store->slug) }}',
        method: 'POST',
        data: data,
        success: function(response) {
            if (response.status == 'success') {
                $('.spinner-view').html(`
                    <div class="spin-wheel-container">
                        <div id="spinwheel">
                            <button id="spin" class="spinbtn">{{ __('Spin') }}</button>
                            <div class="spinnerwheel"></div>
                        </div>
                        <div id="spin-arrow" class="wheel-spin-arrow">
                            <svg width="83" height="74" viewBox="0 0 83 74" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M32.9489 5.12466C33.8289 3.59888 35.0943 2.3319 36.618 1.45104C38.1417 0.570174 39.8701 0.106445 41.6294 0.106445C43.3888 0.106445 45.1171 0.570174 46.6409 1.45104C48.1646 2.3319 49.43 3.59888 50.31 5.12466L80.9178 58.1922C81.7993 59.7185 82.264 61.4504 82.265 63.2137C82.2659 64.9769 81.8032 66.7094 80.9234 68.2366C80.0435 69.7639 78.7776 71.0322 77.2529 71.9139C75.7282 72.7955 73.9986 73.2595 72.238 73.2591H11.0223C9.26269 73.259 7.53405 72.7951 6.01016 71.9139C4.48627 71.0327 3.22083 69.7653 2.34102 68.2391C1.46121 66.7128 0.998036 64.9815 0.998047 63.2192C0.998058 61.4569 1.46125 59.7256 2.34108 58.1994L32.9489 5.12466Z" fill="currentColor"/>
                            </svg>
                        </div>
                    </div>
                    <p class="spin-subtitle">{{ __('Click SPIN to try your luck!') }}</p>
                `);
                setTimeout(waitForD3AndInitialize, 200);
            } else {
                $('.spinner-view').html('<div class="result-container"><h3 style="color: var(--spin-danger);">' + response.message + '</h3></div>');
            }
        }
    });
});
</script>