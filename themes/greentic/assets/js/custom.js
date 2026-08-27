tailwind.config = {
  theme: {
    extend: {
      colors: {
        primary: '#4caf50',
      },
    },
  },
};

// DOM Loaded
document.addEventListener('DOMContentLoaded', () => {
  // Cart Toggle
  const cartPanel = document.getElementById('cart-panel');
  const cartOverlay = document.getElementById('cart-overlay');
  const cartClose = document.getElementById('cart-close');
  const continueShopping = document.getElementById('continue-shopping');
  const cartToggle = document.getElementById('cart-toggle');
  const toggleCart = (show) => {
    cartPanel.classList.toggle('open', show);
    cartOverlay.classList.toggle('open', show);
    document.body.style.overflow = show ? 'hidden' : '';
  };
  if (cartPanel || cartOverlay || cartToggle) {
    cartToggle.addEventListener('click', () => toggleCart(true));
   
    cartOverlay.addEventListener('click', (e) => {
      if (e.target === cartOverlay) toggleCart(false);
    });
  }

  if (cartClose) {
    cartClose.addEventListener('click', () => toggleCart(false));
  }

  if ( continueShopping) {
    continueShopping.addEventListener('click', () => toggleCart(false));
  }
  // Tab Navigation
  const tabBtn = document.querySelectorAll('.tab-btn');
  const tabButtons = document.querySelectorAll('.tab-button');
  const tabContents = document.querySelectorAll('.tab-content');
  tabButtons.forEach(button => {
    button.addEventListener('click', () => {
      // Remove 'active' class from all buttons
      tabButtons.forEach(btn => btn.classList.remove('active'));
      // Add 'active' class to the clicked button
      button.classList.add('active');
      // Hide all tab contents
      tabContents.forEach(content => content.classList.add('hidden'));
      // Show the selected tab content
      const tabId = button.getAttribute('data-tab');
      document.getElementById(tabId)?.classList.remove('hidden');
    });
  });
  tabBtn.forEach((btn) => {
    btn.addEventListener("click", () => {
      const target = btn.getAttribute("data-tab");
      // Remove active styles from all buttons
      tabBtn.forEach((b) => {
        b.classList.remove("bg-primary", "text-white");
        b.classList.add("bg-white", "text-primary");
      });
      // Add active style to clicked button
      btn.classList.remove("bg-white", "text-primary");
      btn.classList.add("bg-primary", "text-white");
      // Hide all tab contents
      tabContents.forEach((content) => {
        content.classList.add("hidden");
      });
      // Show target tab content
      const activeTab = document.getElementById(target);
      if (activeTab) {
        activeTab.classList.remove("hidden");
      }
    });
  });
  // Show only the first tab content on load
  tabContents.forEach((content, index) => {
    if (index !== 0) content.classList.add("hidden");
  });
  if (document.documentElement.dir === 'rtl') {
  new Swiper('.home-banner-slider', {
    slidesPerView: 1,
    spaceBetween: 30,
    loop: false,
    rtl:true,
    pagination: {
      el: '.swiper-pagination',
      clickable: true,
    },
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
  });
}else{
  new Swiper('.home-banner-slider', {
    slidesPerView: 1,
    spaceBetween: 30,
    loop: false,
    pagination: {
      el: '.swiper-pagination',
      clickable: true,
    },
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
  });
}

  // logo Swiper
  if (document.querySelector('.logo-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const logoSwiper = new Swiper('.logo-swiper', {
      slidesPerView: 2,
      spaceBetween: 20,
      centeredSlides: false,
      loop: false,
      rtl:true,
      speed: 500,
      autoplay: {
        duration: 3000,
        disableOnInteraction: false,
      },
      breakpoints: {
        576: {
          slidesPerView: 3,
        },
        768: {
          slidesPerView: 4,
        },
        1024: {
          slidesPerView: 6,
        },
      }
    });
    }else{
      const logoSwiper = new Swiper('.logo-swiper', {
        slidesPerView: 2,
        spaceBetween: 20,
        centeredSlides: false,
        loop: false,
        speed: 500,
        autoplay: {
          duration: 3000,
          disableOnInteraction: false,
        },
        breakpoints: {
          576: {
            slidesPerView: 3,
          },
          768: {
            slidesPerView: 4,
          },
          1024: {
            slidesPerView: 6,
          },
        }
      });
    }
  }
  // blog swiper
  if (document.querySelector('.blog-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const blogSwiper = new Swiper('.blog-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl:true,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next.blog-arrow',
        prevEl: '.swiper-button-prev.blog-arrow',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1400: {
          slidesPerView: 4,
        },
      }
    });
  }else{
    const blogSwiper = new Swiper('.blog-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next.blog-arrow',
        prevEl: '.swiper-button-prev.blog-arrow',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1400: {
          slidesPerView: 4,
        },
      }
    });
  }
  }
  if (document.querySelector('.testimonial-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const testimonialSwiper = new Swiper('.testimonial-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl:true,
      speed: 500,
      pagination: {
        el: '.testimonial-swiper .swiper-pagination',
        clickable: true,
      },
      breakpoints: {
        640: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
      }
    });
  }else{
    const testimonialSwiper = new Swiper('.testimonial-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      pagination: {
        el: '.testimonial-swiper .swiper-pagination',
        clickable: true,
      },
      breakpoints: {
        640: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
      }
    });
  }
  }
  // product Swiper
  if (document.querySelector('.product-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const productSwiper = new Swiper('.product-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl:true,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }else{
    const productSwiper = new Swiper('.product-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }
  }
  // product Swiper
  if (document.querySelector('.product-tab-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const productSwiper = new Swiper('.product-tab-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl:true,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }else{
    const productSwiper = new Swiper('.product-tab-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }
  }
  // team Swiper
  if (document.querySelector('.team-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const teamSwiper = new Swiper('.team-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl: true,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next.team-arrow',
        prevEl: '.swiper-button-prev.team-arrow',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }else{
    const teamSwiper = new Swiper('.team-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next.team-arrow',
        prevEl: '.swiper-button-prev.team-arrow',
      },
      breakpoints: {
        576: {
          slidesPerView: 2,
        },
        768: {
          slidesPerView: 2,
        },
        1024: {
          slidesPerView: 3,
        },
        1280: {
          slidesPerView: 4,
        },
      }
    });
  }
  }
    // Category Swiper
  if (document.querySelector('.category-swiper')) {
    if (document.documentElement.dir === 'rtl') {
    const categorySwiper = new Swiper('.category-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      rtl: true,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        0: {
          slidesPerView: 2,
        },
        640: {
          slidesPerView: 4,
        },
        1024: {
          slidesPerView: 5,
        },
        1280: {
          slidesPerView: 8,
        },
      }
    });
  }else{
    const categorySwiper = new Swiper('.category-swiper', {
      slidesPerView: 1,
      spaceBetween: 20,
      loop: false,
      speed: 500,
      navigation: {
        nextEl: '.swiper-button-next',
        prevEl: '.swiper-button-prev',
      },
      breakpoints: {
        0: {
          slidesPerView: 2,
        },
        640: {
          slidesPerView: 4,
        },
        1024: {
          slidesPerView: 5,
        },
        1280: {
          slidesPerView: 8,
        },
      }
    });
  }
  }
  // Product Image Slider + Thumbnails
  if (document.documentElement.dir === 'rtl') {
  var sliderThumbnail = new Swiper(".thumbnail-slider", {
    slidesPerView: 3,
    spaceBetween: 15,
    speed: 500,
    centeredSlides: false,
    centeredSlidesBounds: true,
    watchOverflow: true,
    watchSlidesVisibility: false,
    watchSlidesProgress: false,
    rtl:true,
    breakpoints: {
      640: {
        slidesPerView: 4,
      },
    },
  });
}else{
  var sliderThumbnail = new Swiper(".thumbnail-slider", {
    slidesPerView: 3,
    spaceBetween: 15,
    speed: 500,
    centeredSlides: false,
    centeredSlidesBounds: true,
    watchOverflow: true,
    watchSlidesVisibility: false,
    watchSlidesProgress: false,
    breakpoints: {
      640: {
        slidesPerView: 4,
      },
    },
  });
}
if (document.documentElement.dir === 'rtl') {
  var sliderMain = new Swiper(".main-image-slider", {
    spaceBetween: 15,
    watchOverflow: true,
    watchSlidesVisibility: true,
    watchSlidesProgress: true,
    rtl:true,
    speed: 500,
    preventInteractionOnTransition: true,
    navigation: {
      nextEl: '.swiper-button-next.pdp-arrow',
      prevEl: '.swiper-button-prev.pdp-arrow',
    },
    thumbs: {
      swiper: sliderThumbnail
    }
  });
  sliderMain.on('slideChangeTransitionStart', function () {
    sliderThumbnail.slideTo(sliderMain.activeIndex);
  });
  sliderThumbnail.on('transitionStart', function () {
    sliderMain.slideTo(sliderThumbnail.activeIndex);
  });
}
else{

  var sliderMain = new Swiper(".main-image-slider", {
    spaceBetween: 15,
    watchOverflow: true,
    watchSlidesVisibility: true,
    watchSlidesProgress: true,
    speed: 500,
    preventInteractionOnTransition: true,
    navigation: {
      nextEl: '.swiper-button-next.pdp-arrow',
      prevEl: '.swiper-button-prev.pdp-arrow',
    },
    thumbs: {
      swiper: sliderThumbnail
    }
  });
  sliderMain.on('slideChangeTransitionStart', function () {
    sliderThumbnail.slideTo(sliderMain.activeIndex);
  });
  sliderThumbnail.on('transitionStart', function () {
    sliderMain.slideTo(sliderThumbnail.activeIndex);
  });
}
  // Mobile Menu
  const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
  const mobileMenu = document.getElementById('mobile-menu');
  const closeMobileMenu = document.getElementById('close-mobile-menu');
  const overlay = document.querySelector('.overlay');
  mobileMenuToggle?.addEventListener('click', () => {
    mobileMenu.classList.remove('hidden');
    overlay.classList.add('open');
    document.body.classList.add('no-scroll');
    setTimeout(() => mobileMenu.classList.add('show'), 10);
  });
  function closeMenu() {
    mobileMenu.classList.remove('show');
    overlay.classList.remove('open');
    document.body.classList.remove('no-scroll');
    setTimeout(() => mobileMenu.classList.add('hidden'), 300);
  }
  closeMobileMenu?.addEventListener('click', closeMenu);
  overlay?.addEventListener('click', closeMenu);
  document.querySelectorAll('.mobile-dropdown-toggle').forEach(toggle => {
    toggle.addEventListener('click', function () {
      const dropdown = this.nextElementSibling;
      const icon = this.querySelector('.fa-chevron-down');
      dropdown.classList.toggle('show');
      icon.classList.toggle('rotate-180');
    });
  });
  // Toggle search popup
  const searchToggle = document.getElementById('search-toggle');
  const searchPopup = document.getElementById('search-popup-container');
  const closeSearch = document.getElementById('close-search');
  searchToggle.addEventListener('click', () => {
    searchPopup.classList.remove('hidden');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('search-input').focus();
  });
  closeSearch.addEventListener('click', () => {
    searchPopup.classList.add('hidden');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
  });
  searchPopup.addEventListener('click', (e) => {
    if (e.target === searchPopup || e.target === overlay) {
      searchPopup.classList.add('hidden');
      overlay.classList.remove('open');
      document.body.style.overflow = '';
    }
  });
});
// dropdown js
const dropdownButtons = document.querySelectorAll("[data-dropdown-toggle]");
dropdownButtons.forEach((button) => {
  const menuId = button.getAttribute("data-dropdown-toggle");
  const menu = document.querySelector(`[data-dropdown-menu='${menuId}']`);
  if (menu) {
    button.addEventListener("click", function (event) {
      event.stopPropagation();
      // Hide all other dropdowns
      document.querySelectorAll("[data-dropdown-menu]").forEach((otherMenu) => {
        if (otherMenu !== menu) {
          otherMenu.classList.add("hidden");
        }
      });
      // Toggle current dropdown
      menu.classList.toggle("hidden");
    });
    // Optional: close dropdown if clicking outside
    document.addEventListener("click", function (event) {
      if (!menu.contains(event.target) && !button.contains(event.target)) {
        menu.classList.add("hidden");
      }
    });
  }
});
// Quantity Controls
// document.querySelectorAll('.quantity-increment').forEach(function (button) {
//   button.addEventListener('click', function () {
//     const input = this.parentElement.querySelector('.quantity');
//     let quantity = parseInt(input.value) || 0;
//     input.value = quantity + 1;
//   });
// });
// document.querySelectorAll('.quantity-decrement').forEach(function (button) {
//   button.addEventListener('click', function () {
//     const input = this.parentElement.querySelector('.quantity');
//     let quantity = parseInt(input.value) || 0;
//     if (quantity > 1) {
//       input.value = quantity - 1;
//     }
//   });
// });
// list and grid view
const gridViewBtn = document.getElementById('grid-view-btn');
const listViewBtn = document.getElementById('list-view-btn');
const productsContainer = document.getElementById('products-container');
if (gridViewBtn && listViewBtn && productsContainer) {
  gridViewBtn.addEventListener('click', () => {
    productsContainer.classList.remove('list-view');
    productsContainer.classList.add('grid-view');
    productsContainer.classList.remove('space-y-5', 'xl:space-y-6');
    productsContainer.classList.add('grid', 'grid-cols-1', 'sm:grid-cols-2', 'lg:grid-cols-3', 'gap-4', 'md:gap-6');
    gridViewBtn.classList.add('active');
    listViewBtn.classList.remove('active');
  });
  listViewBtn.addEventListener('click', () => {
    productsContainer.classList.remove('grid-view');
    productsContainer.classList.add('list-view');
    productsContainer.classList.remove('grid', 'grid-cols-1', 'sm:grid-cols-2', 'lg:grid-cols-3', 'gap-4', 'md:gap-6');
    productsContainer.classList.add('space-y-5', 'xl:space-y-6');
    listViewBtn.classList.add('active');
    gridViewBtn.classList.remove('active');
  });
}
// filter popup
const filterBtn = document.getElementById('filter-icon');
const filterPanel = document.getElementById('mobile-filter');
const filterOverlay = document.getElementById('filter-overlay');
const filterClose = document.getElementById('filter-close');
function openFilter() {
  if (filterPanel && filterOverlay) {
    filterPanel.classList.remove('-translate-x-full');
    filterOverlay.classList.add('open');
    document.body.classList.add('no-scroll');
  }
}
function closeFilter() {
  if (filterPanel && filterOverlay) {
    filterPanel.classList.add('-translate-x-full');
    filterOverlay.classList.remove('open');
    document.body.classList.remove('no-scroll');
  }
}
// Attach event listeners only if elements exist
if (filterBtn) {
  filterBtn.addEventListener('click', openFilter);
}
if (filterClose) {
  filterClose.addEventListener('click', closeFilter);
}
if (filterOverlay) {
  filterOverlay.addEventListener('click', closeFilter);
}
function handleResize() {
  if (window.innerWidth >= 1024) {
    closeFilter(); // Close if resizing to desktop
  }
}
window.addEventListener('resize', handleResize);

// FAQ Toggle
      // FAQ Toggle
      const faqButtons = document.querySelectorAll('.faq-button button');
      faqButtons.forEach(button => {
        button.addEventListener('click', () => {
          const content = button.nextElementSibling;
          const icon = button.querySelector('svg');
          content.classList.toggle('hidden');
          icon.innerHTML = content.classList.contains('hidden') ? '<path d="M5 12h14"/><path d="M12 5v14"/>' : '<path d="M5 12h14"/>';
        });
      });