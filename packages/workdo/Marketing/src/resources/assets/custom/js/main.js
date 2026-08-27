"use strict";

$(function () {
    commonLoader();
});

$(document).ready(function () {
    if ($(".dataTable").length > 0) {
        $(".dataTable").dataTable({
            language: dataTabelLang,
            "columnDefs": [
                { "visible": false, "targets": 0 }
            ]
        });
    }
});

$(document).on("click", '.bs-pass-para',function () {
    const swalWithBootstrapButtons = Swal.mixin({
        customClass: {
            confirmButton: 'btn btn-success',
            cancelButton: 'btn btn-danger'
        },
        buttonsStyling: false
    })
    swalWithBootstrapButtons.fire({
        title: $(this).data('confirm'),
        text: $(this).data('text'),
        icon: 'warning',
        showCancelButton: true,
    confirmButtonText: 'Yes',
        cancelButtonText: 'No',
        reverseButtons: false,
    }).then((result) => {
        if (result.isConfirmed) {

            document.getElementById($(this).data('confirm-yes')).submit();

        } else if (
            result.dismiss === Swal.DismissReason.cancel
        ) {
        }
    })
});





function arrayToJson(form) {
    var data = $(form).serializeArray();
    var indexed_array = {};

    $.map(data, function (n, i) {
        indexed_array[n['name']] = n['value'];
    });

    return indexed_array;
}



function taskCheckbox() {
    var checked = 0;
    var count = 0;
    var percentage = 0;

    count = $("#check-list input[type=checkbox]").length;
    checked = $("#check-list input[type=checkbox]:checked").length;
    percentage = parseInt(((checked / count) * 100), 10);
    if (isNaN(percentage)) {
        percentage = 0;
    }
    $(".custom-label").text(percentage + "%");
    $('#taskProgress').css('width', percentage + '%');


    $('#taskProgress').removeClass('bg-warning');
    $('#taskProgress').removeClass('bg-primary');
    $('#taskProgress').removeClass('bg-success');
    $('#taskProgress').removeClass('bg-danger');

    if (percentage <= 15) {
        $('#taskProgress').addClass('bg-danger');
    } else if (percentage > 15 && percentage <= 33) {
        $('#taskProgress').addClass('bg-warning');
    } else if (percentage > 33 && percentage <= 70) {
        $('#taskProgress').addClass('bg-primary');
    } else {
        $('#taskProgress').addClass('bg-success');
    }
}

var Charts = (function () {

    // Variable

    var $toggle = $('[data-toggle="chart"]');
    var mode = 'light';//(themeMode) ? themeMode : 'light';
    var fonts = {
        base: 'Open Sans'
    }

    // Colors
    var colors = {
        gray: {
            100: '#f6f9fc',
            200: '#e9ecef',
            300: '#dee2e6',
            400: '#ced4da',
            500: '#adb5bd',
            600: '#8898aa',
            700: '#525f7f',
            800: '#32325d',
            900: '#212529'
        },
        theme: {
            'default': '#172b4d',
            'primary': '#5e72e4',
            'secondary': '#f4f5f7',
            'info': '#11cdef',
            'success': '#2dce89',
            'danger': '#f5365c',
            'warning': '#fb6340'
        },
        black: '#12263F',
        white: '#FFFFFF',
        transparent: 'transparent',
    };


    // Methods

    // Chart.js global options
    function chartOptions() {

        // Options
        var options = {
            defaults: {
                global: {
                    responsive: true,
                    maintainAspectRatio: false,
                    defaultColor: (mode == 'dark') ? colors.gray[700] : colors.gray[600],
                    defaultFontColor: (mode == 'dark') ? colors.gray[700] : colors.gray[600],
                    defaultFontFamily: fonts.base,
                    defaultFontSize: 13,
                    layout: {
                        padding: 0
                    },
                    legend: {
                        display: false,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 16
                        }
                    },
                    elements: {
                        point: {
                            radius: 0,
                            backgroundColor: colors.theme['primary']
                        },
                        line: {
                            tension: .4,
                            borderWidth: 4,
                            borderColor: colors.theme['primary'],
                            backgroundColor: colors.transparent,
                            borderCapStyle: 'rounded'
                        },
                        rectangle: {
                            backgroundColor: colors.theme['warning']
                        },
                        arc: {
                            backgroundColor: colors.theme['primary'],
                            borderColor: (mode == 'dark') ? colors.gray[800] : colors.white,
                            borderWidth: 4
                        }
                    },
                    tooltips: {
                        enabled: true,
                        mode: 'index',
                        intersect: false,
                    }
                },
                doughnut: {
                    cutoutPercentage: 83,
                    legendCallback: function (chart) {
                        var data = chart.data;
                        var content = '';

                        data.labels.forEach(function (label, index) {
                            var bgColor = data.datasets[0].backgroundColor[index];

                            content += '<span class="chart-legend-item">';
                            content += '<i class="chart-legend-indicator" style="background-color: ' + bgColor + '"></i>';
                            content += label;
                            content += '</span>';
                        });

                        return content;
                    }
                }
            }
        }

        // yAxes
        Chart.scaleService.updateScaleDefaults('linear', {
            gridLines: {
                borderDash: [2],
                borderDashOffset: [2],
                color: (mode == 'dark') ? colors.gray[900] : colors.gray[300],
                drawBorder: false,
                drawTicks: false,
                drawOnChartArea: true,
                zeroLineWidth: 0,
                zeroLineColor: 'rgba(0,0,0,0)',
                zeroLineBorderDash: [2],
                zeroLineBorderDashOffset: [2]
            },
            ticks: {
                beginAtZero: true,
                padding: 10,
                callback: function (value) {
                    if (!(value % 10)) {
                        return value
                    }
                }
            }
        });

        // xAxes
        Chart.scaleService.updateScaleDefaults('category', {
            gridLines: {
                drawBorder: false,
                drawOnChartArea: false,
                drawTicks: false
            },
            ticks: {
                padding: 20
            },
            maxBarThickness: 10
        });

        return options;

    }

    // Parse global options
    function parseOptions(parent, options) {
        for (var item in options) {
            if (typeof options[item] !== 'object') {
                parent[item] = options[item];
            } else {
                parseOptions(parent[item], options[item]);
            }
        }
    }

    // Push options
    function pushOptions(parent, options) {
        for (var item in options) {
            if (Array.isArray(options[item])) {
                options[item].forEach(function (data) {
                    parent[item].push(data);
                });
            } else {
                pushOptions(parent[item], options[item]);
            }
        }
    }

    // Pop options
    function popOptions(parent, options) {
        for (var item in options) {
            if (Array.isArray(options[item])) {
                options[item].forEach(function (data) {
                    parent[item].pop();
                });
            } else {
                popOptions(parent[item], options[item]);
            }
        }
    }

    // Toggle options
    function toggleOptions(elem) {
        var options = elem.data('add');
        var $target = $(elem.data('target'));
        var $chart = $target.data('chart');

        if (elem.is(':checked')) {

            // Add options
            pushOptions($chart, options);

            // Update chart
            $chart.update();
        } else {

            // Remove options
            popOptions($chart, options);

            // Update chart
            $chart.update();
        }
    }

    // Update options
    function updateOptions(elem) {
        var options = elem.data('update');
        var $target = $(elem.data('target'));
        var $chart = $target.data('chart');

        // Parse options
        parseOptions($chart, options);

        // Toggle ticks
        toggleTicks(elem, $chart);

        // Update chart
        $chart.update();
    }

    // Toggle ticks
    function toggleTicks(elem, $chart) {

        if (elem.data('prefix') !== undefined || elem.data('prefix') !== undefined) {
            var prefix = elem.data('prefix') ? elem.data('prefix') : '';
            var suffix = elem.data('suffix') ? elem.data('suffix') : '';

            // Update ticks
            $chart.options.scales.yAxes[0].ticks.callback = function (value) {
                if (!(value % 10)) {
                    return prefix + value + suffix;
                }
            }

            // Update tooltips
            $chart.options.tooltips.callbacks.label = function (item, data) {
                var label = data.datasets[item.datasetIndex].label || '';
                var yLabel = item.yLabel;
                var content = '';

                if (data.datasets.length > 1) {
                    content += '<span class="popover-body-label mr-auto">' + label + '</span>';
                }

                content += '<span class="popover-body-value">' + prefix + yLabel + suffix + '</span>';
                return content;
            }

        }
    }


    // Events

    // Parse global options
    if (window.Chart) {
        parseOptions(Chart, chartOptions());
    }

    // Toggle options
    $toggle.on({
        'change': function () {
            var $this = $(this);

            if ($this.is('[data-add]')) {
                toggleOptions($this);
            }
        },
        'click': function () {
            var $this = $(this);

            if ($this.is('[data-update]')) {
                updateOptions($this);
            }
        }
    });


    // Return

    return {
        colors: colors,
        fonts: fonts,
        mode: mode
    };
})();

function commonLoader() {
    if ($(".datepicker").length) {
        $('.datepicker').daterangepicker({
            locale: date_picker_locale,
            singleDatePicker: true,
        });
    }

    if ($(".date-rangepicker").length > 0) {
        $('.date-rangepicker').daterangepicker({
            locale: date_picker_locale,
        });
    }


    // jQuery(document).ready(function(){
    //     if ($(".select2").length > 0) {
    //     jQuery('.select2').select2({
    //         disableOnMobile: false,
    //         nativeOnMobile: false
    //     });
    // }
    // });
    if ($("#selector").length > 0)
   $("#selector").select2();

    if ($(".summernote-simple").length) {
        $('.summernote-simple').summernote({
            dialogsInBody: !0,
            minHeight: 200,
            toolbar: [
                ['style', ['style']],
                ["font", ["bold", "italic", "underline", "clear", "strikethrough"]],
                ['fontname', ['fontname']],
                ['color', ['color']],
                ["para", ["ul", "ol", "paragraph"]],
                ['misc', ['emoji']],
            ]
        });
    }

    if ($(".jscolor").length > 0) {
        jscolor.installByClassName("jscolor");
    }

    // for Choose file
    $(document).on('change', 'input[type=file]', function () {
        var fileclass = $(this).attr('data-filename');
        var finalname = $(this).val().split('\\').pop();
        $('.' + fileclass).html(finalname);
    });
}



function selectFile(elementid){
  $(`.${elementid}`).trigger('click');
  $(`.${elementid}`).change(function() {
      var url = this.value;
      var ext = url.substring(url.lastIndexOf('.') + 1).toLowerCase();
      if (this.files && this.files[0]&& (ext == "gif" || ext == "png" || ext == "jpeg" || ext == "jpg")) {
          var reader = new FileReader();
          reader.onload = function (e) {
              $(`#${elementid}`).attr('src', e.target.result);
              $(`#section_${elementid}`).attr('src', e.target.result);
              $(`#${elementid}_preview`).attr('src', e.target.result);
          }
          reader.readAsDataURL(this.files[0]);
      }else{
          $(`#${elementid}`).attr('src', '/assets/no_preview.png');

          $(`#section_${elementid}`).attr('src', '/assets/no_preview.png');
      }
  });

}






