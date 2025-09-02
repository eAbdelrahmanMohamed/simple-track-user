(function () {
    function getCookie(n) {
        var v = document.cookie.match('(^|;) ?' + n + '=([^;]*)(;|$)');
        return v ? decodeURIComponent(v[2]) : null;
    }
    function setCookie(n, v, maxAge) {
        document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=' + maxAge;
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
        setCookie('sut_device_id', gen(), 60 * 60 * 24 * 365);
    }
    // session_id (30 minutes sliding)
    if (!getCookie('sut_session_id')) {
        setCookie('sut_session_id', gen(), 30 * 60);
    }

    // Request precise location once per session
    try {
        if (window.SUT_TRACK && navigator.geolocation) {
            var sentKey = SUT_TRACK.sessionKey || 'sut_geo_sent';
            if (!sessionStorage.getItem(sentKey)) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    var lat = pos.coords.latitude;
                    var lng = pos.coords.longitude;
                    // Persist to cookie for server-side preference
                    setCookie('sut_geo', lat.toFixed(7) + ',' + lng.toFixed(7), 30 * 60);
                    // Send to server
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
