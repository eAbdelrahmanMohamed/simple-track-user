(function () {
    function getCookie(n) {
        var v = document.cookie.match('(^|;) ?' + n + '=([^;]*)(;|$)');
        return v ? v[2] : null;
    }
    function gen() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0,
                v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    // device_id persistent (1 year)
    if (!getCookie('sut_device_id')) {
        document.cookie = 'sut_device_id=' + gen() + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    }
    // session_id (30 minutes sliding)
    if (!getCookie('sut_session_id')) {
        document.cookie = 'sut_session_id=' + gen() + ';path=/;max-age=' + (30 * 60);
    }

    // Request precise location once per session
    try {
        if (window.SUT_TRACK && navigator.geolocation) {
            var sentKey = SUT_TRACK.sessionKey || 'sut_geo_sent';
            if (!sessionStorage.getItem(sentKey)) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    var lat = pos.coords.latitude;
                    var lng = pos.coords.longitude;
                    fetch(SUT_TRACK.geoEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': SUT_TRACK.nonce || ''
                        },
                        body: JSON.stringify({ lat: lat, lng: lng })
                    }).then(function(){
                        sessionStorage.setItem(sentKey, '1');
                    }).catch(function(){});
                }, function(){ /* user denied or unavailable */ }, { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 });
            }
        }
    } catch (e) {}
})();
