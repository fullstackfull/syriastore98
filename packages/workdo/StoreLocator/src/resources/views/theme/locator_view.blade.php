@if ($locator_settings->store_locator_enabled ?? '' == 'on')
    <script src="{{ asset('packages/workdo/StoreLocator/src/resources/assets/js/leaflet.js')}}"></script>
    <link rel="stylesheet" href="{{ asset('packages/workdo/StoreLocator/src/resources/assets/css/leaflet.css')}}"/>

    <div id="map" style="height: 400px;"></div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var latitude = "{{ $locator_settings->store_latitude }}";
            var longitude = "{{ $locator_settings->store_longitude }}";
            var storeName = "{{ ucfirst($store->name) }}";
            var storeAddress = "{{ $locator_settings->store_address }}";

            var map = L.map('map').setView([latitude, longitude], 13);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);

            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}`)
                .then(response => response.json())
                .then(data => {
                    var areaName = storeAddress;
                    var popupContent = `<strong class="text-center">${storeName}</strong><br>${areaName}`;
                    L.marker([latitude, longitude]).addTo(map)
                        .bindPopup(popupContent)
                        .openPopup();
                })
                .catch(error => {});
        });
    </script>
@endif
