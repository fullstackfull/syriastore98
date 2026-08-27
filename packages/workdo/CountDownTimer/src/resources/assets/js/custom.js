
function closePopup() {
    if (window.innerWidth > 767) {
        if (jQuery.cookie('countdown_popup') == 'closed') {
            jQuery('.countdown-popup').remove();
            $(".countdown-popup").removeClass("active");
            $(".overlay").removeClass("active");
        } else {
            $(".countdown-popup").removeClass("d-none");
            $(".overlay").removeClass("d-none");
        }
        jQuery('.closePopup , .overlay').bind('click', function() {
            jQuery('.countdown-popup').remove();
            $(".countdown-popup").removeClass("active");
            $(".overlay").removeClass("active");
            jQuery.cookie('countdown_popup', 'closed', {
                expires: 1,
                path: '/'
            });
        });
    }
}
closePopup();

// TIMER COUNTER
function makeTimer() {
    var endTime = new Date(countDownDateTime);
    endTime = Date.parse(endTime) / 1000;

    var now = new Date();
    now = Date.parse(now) / 1000;

    var timeLeft = endTime - now;

    var months = Math.floor(timeLeft / (30 * 24 * 60 * 60));
    var weeks = Math.floor(timeLeft / (7 * 24 * 60 * 60));
    var days = Math.floor(timeLeft / 86400);
    var hours = Math.floor((timeLeft - days * 86400) / 3600);
    var minutes = Math.floor((timeLeft - days * 86400 - hours * 3600) / 60);
    var seconds = Math.floor(
        timeLeft - days * 86400 - hours * 3600 - minutes * 60
    );

    months  = (months < 10) ? "0" + months : months;
    weeks   = (weeks < 10) ? "0" + weeks : weeks;
    days    = (days < 10) ? "0" + days : days;
    hours   = (hours < 10) ? "0" + hours : hours;
    minutes = (minutes < 10) ? "0" + minutes : minutes;
    seconds = (seconds < 10) ? "0" + seconds : seconds;

    $(".count-months").html(months);
    $(".count-weeks").html(weeks);
    $(".count-days").html(days);
    $(".count-hours").html(hours);
    $(".count-minutes").html(minutes);
    $(".count-seconds").html(seconds);
}
setInterval(function () {
    makeTimer();
}, 1000);
