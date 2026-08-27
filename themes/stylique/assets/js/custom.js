tailwind.config = {
  theme: {
    extend: {
      fontFamily: {
        sans: ['Roboto', 'sans-serif'],
      },
      colors: {
        orange: {
          500: '#f8796c',
        },
        primary: '#f8796c',
      },
    },
  },
};


document.addEventListener("DOMContentLoaded", function () {
  const categoriesWrap = document.querySelector(".categories-wrap .btn");
  const categoryDropdown = document.querySelector(".category-dropdown");

  categoriesWrap.addEventListener("click", function (e) {
    e.preventDefault();
    categoryDropdown.classList.toggle("open");
  });
});



// Toggle search popup
const searchToggle = document.getElementById('search-toggle');
const searchPopup = document.getElementById('search-popup-container');
const closeSearch = document.getElementById('close-search');

searchToggle.addEventListener('click', () => {
  searchPopup.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  document.getElementById('search-input').focus();
});

closeSearch.addEventListener('click', () => {
  searchPopup.classList.add('hidden');
  document.body.style.overflow = '';
});


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

if (continueShopping) {
  continueShopping.addEventListener('click', () => toggleCart(false));
}
// if (cartPanel && cartOverlay && cartToggle && cartClose && continueShopping) {
//     cartToggle.addEventListener('click', () => toggleCart(true));
//     cartClose.addEventListener('click', () => toggleCart(false));
//     continueShopping.addEventListener('click', () => toggleCart(false));
//     cartOverlay.addEventListener('click', (e) => {
//         if (e.target === cartOverlay) toggleCart(false);
//     });
// }

// Quantity Controls
// document.querySelectorAll('.quantity-increment').forEach(function (button) {
//     button.addEventListener('click', function () {
//         const input = this.parentElement.querySelector('.quantity');
//         let quantity = parseInt(input.value) || 0;
//         input.value = quantity + 1;
//     });
// });

// document.querySelectorAll('.quantity-decrement').forEach(function (button) {
//     button.addEventListener('click', function () {
//         const input = this.parentElement.querySelector('.quantity');
//         let quantity = parseInt(input.value) || 0;
//         if (quantity > 1) {
//             input.value = quantity - 1;
//         }
//     });
// });

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

// Product Category Tabs
const tabBtn = document.querySelectorAll(".tab-button");
const tabButtons = document.querySelectorAll(".tab-btn");
const tabContents = document.querySelectorAll(".tab-content");
tabButtons.forEach((button) => {
  button.addEventListener("click", () => {
    // Remove active class from all buttons and tab contents
    tabButtons.forEach((btn) =>
      btn.classList.remove("active", "border-[var(--primary-color)]")
    );
    tabButtons.forEach((btn) =>
      btn.classList.add("border-transparent")
    );
    tabContents.forEach((content) => content.classList.add("hidden"));
    tabContents.forEach((content) =>
      content.classList.remove("active")
    );

    // Add active class to clicked button
    button.classList.add("active", "border-[var(--primary-color)]");
    button.classList.remove("border-transparent");

    // Show the corresponding tab content
    const tabName = button.getAttribute("data-tab");
    const tabContent = document.getElementById(`${tabName}-tab`);
    if (tabContent) {
      tabContent.classList.remove("hidden");
      tabContent.classList.add("active");

      // Reinitialize Swiper if this is the "all" tab (which has a Swiper instance)
      if (tabName === "all" && featuredProductsSlider) {
        featuredProductsSlider.update();
      }
    }
  });
});

tabBtn.forEach(button => {
  button.addEventListener('click', () => {
    // Remove 'active' class from all buttons
    tabBtn.forEach(btn => btn.classList.remove('active'));

    // Add 'active' class to the clicked button
    button.classList.add('active');

    // Hide all tab contents
    tabContents.forEach(content => content.classList.add('hidden'));

    // Show the selected tab content
    const tabId = button.getAttribute('data-tab');
    document.getElementById(tabId)?.classList.remove('hidden');
  });
});

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




// Hero Slider
if (document.documentElement.dir === 'rtl') {
  const heroSlider = new Swiper(".hero-slider", {
    loop: true,

    rtl: true,
    spaceBetween: 10,
    // autoplay: {
    //   delay: 5000,
    //   disableOnInteraction: false,
    // },
    pagination: {
      el: ".hero-slider .swiper-pagination",
      clickable: true,
    },
  });
}
else {
  const heroSlider = new Swiper(".hero-slider", {
    loop: true,
    spaceBetween: 10,
    // autoplay: {
    //   delay: 5000,
    //   disableOnInteraction: false,
    // },
    pagination: {
      el: ".hero-slider .swiper-pagination",
      clickable: true,
    },
  });
}


// Featured Products Slider
if (document.documentElement.dir === 'rtl') {
  const featuredProductsSlider = new Swiper(".featured-products", {
    slidesPerView: 1,
    spaceBetween: 15,
    loop: false,
    rtl: true,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
      1280: {
        slidesPerView: 4,
      },
    },
  });
} else {
  const featuredProductsSlider = new Swiper(".featured-products", {
    slidesPerView: 1,
    spaceBetween: 15,
    loop: false,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
      1280: {
        slidesPerView: 4,
      },
    },
  });
}

// Testimonials Slider
if (document.documentElement.dir === 'rtl') {
  const testimonialsSlider = new Swiper(".testimonials-slider", {
    slidesPerView: 1,
    spaceBetween: 10,
    loop: false,
    rtl: true,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
    },
  });
} else {
  const testimonialsSlider = new Swiper(".testimonials-slider", {
    slidesPerView: 1,
    spaceBetween: 10,
    loop: false,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
    },
  });
}

// blog Slider
if (document.documentElement.dir === 'rtl') {
  const blogslider = new Swiper(".blog-slider", {
    slidesPerView: 1,
    spaceBetween: 10,
    loop: false,
    rtl: true,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      600: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
      1280: {
        slidesPerView: 4,
      },
    },
  });
} else {
  const blogslider = new Swiper(".blog-slider", {
    slidesPerView: 1,
    spaceBetween: 10,
    loop: false,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      600: {
        slidesPerView: 2,
      },
      1024: {
        slidesPerView: 3,
      },
      1280: {
        slidesPerView: 4,
      },
    },
  });
}

// partnerSwiper Swiper
if (document.documentElement.dir === 'rtl') {
  const partnerSwiper = new Swiper(".partnerSwiper", {
    slidesPerView: 1,
    spaceBetween: 20,
    loop: false,
    rtl: true,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      1024: {
        slidesPerView: 6,
      },
    },
  });
} else {
  const partnerSwiper = new Swiper(".partnerSwiper", {
    slidesPerView: 1,
    spaceBetween: 20,
    loop: false,
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    breakpoints: {
      576: {
        slidesPerView: 2,
      },
      768: {
        slidesPerView: 3,
      },
      1024: {
        slidesPerView: 6,
      },
    },
  });
}

// Product Image Slider + Thumbnails
let sliderThumbnail = null;
let sliderMain = null;

if (document.documentElement.dir === 'rtl') {
  sliderThumbnail = new Swiper(".thumbnail-slider", {
    slidesPerView: 3,
    spaceBetween: 15,
    speed: 500,
    centeredSlides: false,
    centeredSlidesBounds: true,
    watchOverflow: true,
    watchSlidesVisibility: false,
    watchSlidesProgress: false,
    rtl: true,
    breakpoints: {
      640: {
        slidesPerView: 4,
      },
    },
  });
} else {
  sliderThumbnail = new Swiper(".thumbnail-slider", {
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
  sliderMain = new Swiper(".main-image-slider", {
    spaceBetween: 15,
    watchOverflow: true,
    watchSlidesVisibility: true,
    watchSlidesProgress: true,
    rtl: true,
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
} else {
  sliderMain = new Swiper(".main-image-slider", {
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
}

// team Swiper
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
} else {
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

// counter js
const counters = document.querySelectorAll('.counter');
counters.forEach(counter => {
  const updateCount = () => {
    const target = +counter.getAttribute('data-target');
    const speed = 500;
    const increment = target / speed;
    let count = +counter.innerText;

    if (count < target) {
      counter.innerText = Math.ceil(count + increment);
      setTimeout(updateCount, 10);
    } else {
      // Final adjustment
      counter.innerText = target >= 1000 ? target.toLocaleString() : target;
      if (counter.dataset.target.endsWith('000')) {
        counter.innerText += '+';
      } else if (counter.dataset.target == '100') {
        counter.innerText += '%';
      } else if (counter.dataset.target == '45') {
        counter.innerText += '+';
      }
    }
  };

  updateCount();
});



// Only add event listeners if both sliders are initialized
if (sliderMain && sliderThumbnail) {
  sliderMain.on('slideChangeTransitionStart', function () {
    sliderThumbnail.slideTo(sliderMain.activeIndex);
  });

  sliderThumbnail.on('transitionStart', function () {
    sliderMain.slideTo(sliderThumbnail.activeIndex);
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