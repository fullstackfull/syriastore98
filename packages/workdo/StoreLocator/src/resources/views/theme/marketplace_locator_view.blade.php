
<script src="{{ asset('packages/workdo/StoreLocator/src/resources/assets/js/leaflet.js')}}"></script>
<link rel="stylesheet" href="{{ asset('packages/workdo/StoreLocator/src/resources/assets/css/leaflet.css')}}"/>
<div class="py-10 lg:py-16">
    <div class="md:container w-full mx-auto px-4">
        <div class="text-center mb-8">
            <h3 class="text-xl lg:text-2xl font-bold text-gray-900 mb-2">{{__('Store Locations')}}</h3>
            <p class="text-gray-600">{{__('Find our stores near you')}}</p>
        </div>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div id="map" class="h-96 lg:h-[500px] w-full"></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var map = L.map('map').setView([0, 0], 2);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    var stores = @json($locator_settings);
    var markers = [];

    function getRandomColor() {
        var letters = '0123456789ABCDEF';
        var color = '#';
        for (var i = 0; i < 6; i++) {
            color += letters[Math.floor(Math.random() * 16)];
        }
        return color;
    }

    function createCustomIcon(color) {
        return L.divIcon({
            className: 'custom-div-icon',
            html: `<div><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512"><path fill="${color}" d="M215.7 499.2C267 435 384 279.4 384 192C384 86 298 0 192 0S0 86 0 192c0 87.4 117 243 168.3 307.2c12.3 15.3 35.1 15.3 47.4 0zM192 128a64 64 0 1 1 0 128 64 64 0 1 1 0-128z"/></svg></div>`,
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34]

        });
    }

    function truncateText(text, limit) {
        if (text.length > limit) {
            return text.substring(0, limit) + '...';
        }
        return text;
    }
    function ucfirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    stores.forEach(function(store) {
        let latitude = store.store_latitude;
        let longitude = store.store_longitude;
        let enable = store.store_locator_enabled;
        let storeName = ucfirst(store.storedata.name);

        if (enable === 'on' && latitude !== null && longitude !== null) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}`)
                .then(response => response.json())
                .then(data => {
                    let areaName = store.store_address;
                    let fullContent = `<strong class="text-center">${storeName}</strong><br>${areaName}`;

                    let truncatedContent = truncateText(fullContent, 90);

                    let randomColor = getRandomColor();
                    let marker = L.marker([latitude, longitude], { icon: createCustomIcon(randomColor) }).addTo(map)
                        .bindTooltip(truncatedContent, { permanent: true, direction: 'right' });

                    markers.push(marker);

                    var group = new L.featureGroup(markers);
                    map.fitBounds(group.getBounds().pad(0.2));
                })
                .catch(error => {
                    console.error('Error fetching data:', error);
                });
        } else {
            console.warn('Invalid or missing latitude/longitude for store:', storeName);
        }
    });

});



</script>
