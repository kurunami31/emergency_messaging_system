(function() {
    var loginInitialized = false;
    function init() {
        if (loginInitialized) return;
        loginInitialized = true;
        var loginBtn = document.getElementById('google-login-btn');
        if (loginBtn) loginBtn.addEventListener('click', handleGoogleLogin);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
