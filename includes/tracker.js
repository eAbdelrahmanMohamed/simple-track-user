(function () {
    console.log('Tracker script loaded');
    function getCookie(n) {
        var v = document.cookie.match('(^|;) ?' + n + '=([^;]*)(;|$)');
        return v ? v[2] : null;
    }
    function gen() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0,
                v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    if (!getCookie('sut_device_id')) {
        document.cookie = 'sut_device_id=' + gen() + ';path=/;max-age=' + (60 * 60 * 24 * 365);
    }
})();
