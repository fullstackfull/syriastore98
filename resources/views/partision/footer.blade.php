<footer class="dash-footer">
    <div class="footer-wrapper">
      <div class="py-1">
        <span class="text-muted">© 2021, made with by Joseph for a better web.</span>
      </div>
      <div class="py-1">
        <ul class="list-inline m-0">
          <li class="list-inline-item">
            <a class="link-secondary" href="javascript:">Joseph William</a>
          </li>
          <li class="list-inline-item">
            <a class="link-secondary" href="javascript:">About Us</a>
          </li>
          <li class="list-inline-item">
            <a class="link-secondary" href="javascript:">Blog</a>
          </li>
          <li class="list-inline-item">
            <a class="link-secondary" href="javascript:">Library</a>
          </li>
        </ul>
      </div>
    </div>
  </footer>

{{-- P2-01-11: sidebar behaviour for layouts/main.blade.php, which includes
     this footer and partision.sidebar but neither dash.js nor
     partision.footerlink.

     Without it the workspace chip's switcher renders as a button whose listbox
     nothing ever un-hides — a control that does nothing — on the nine
     super-admin screens that extend that layout.

     The rail toggle is inert on those screens either way, since no host script
     there owns #vertical-nav-toggle; this file only persists and labels a
     state it does not set, so it degrades to a no-op rather than misbehaving. --}}
<script src="{{ asset('js/store-syria-nav.js') }}?v=p02-2"></script>
