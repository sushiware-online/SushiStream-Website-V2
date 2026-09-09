function switchPlayer(type) {
    var tabs = document.querySelectorAll('.tab-btn');
    var mp4Container = document.getElementById('mp4-container');
    var mpgContainer = document.getElementById('mpg-container');
    var mp4Player = document.getElementById('mp4-player');
    var mpgPlayer = document.getElementById('mpg-player');

    tabs.forEach(function (b) { b.classList.remove('active'); });

    if (type === 'mp4') {
        tabs[0].classList.add('active');
        mp4Container.style.display = 'block';
        mpgContainer.style.display = 'none';
        if (mpgPlayer) mpgPlayer.pause();
    } else {
        tabs[1].classList.add('active');
        mp4Container.style.display = 'none';
        mpgContainer.style.display = 'block';
        if (mp4Player) mp4Player.pause();
        if (mpgPlayer) mpgPlayer.play();
    }
}
