<?php
// partials/partials_footer.php
?>
  </main><!-- /main -->
</div><!-- /app-wrap -->

<!-- Bootstrap Bundle (JS + Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>



<script>
/* (বাংলা) Sidebar collapse state persist */
(function(){
  const KEY='sb-collapsed';
  function applyFromStorage(){
    try{ document.body.classList.toggle('sb-collapsed', localStorage.getItem(KEY)==='1'); }catch(e){}
  }
  function save(){ try{
    localStorage.setItem(KEY, document.body.classList.contains('sb-collapsed') ? '1' : '0');
  }catch(e){} }

  document.addEventListener('DOMContentLoaded', function(){
    applyFromStorage();
    const btn = document.getElementById('btnSidebarToggle');
    if (btn){
      btn.addEventListener('click', function(){
        if (window.matchMedia('(min-width: 768px)').matches){
          document.body.classList.toggle('sb-collapsed');
          save();
        }else{
          // mobile: open offcanvas
          const oc = document.getElementById('sidebarOffcanvas');
          if (oc){ new bootstrap.Offcanvas(oc).show(); }
        }
      });
    }
  });
})();
</script>


</body>
</html>


<?php include __DIR__ . '/../app/footer.php'; ?>