
(function(){
  const ov = document.getElementById('events-overlay');
  if(!ov) return;

  document.addEventListener('click', e => {
    if(e.target.matches('[data-overlay-open]')){
      ov.hidden = false;
    }
    if(e.target.matches('[data-overlay-close]')){
      ov.hidden = true;
    }
  });
})();

