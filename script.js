let products = [];

const formatRupiah = (amount) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(amount);

const productGrid = document.querySelector("#product-grid");
const searchInput = document.querySelector("#search-input");
const resultCount = document.querySelector("#result-count");
const emptyState = document.querySelector("#empty-state");
const cartDrawer = document.querySelector("#cart-drawer");
const cartBackdrop = document.querySelector("#cart-backdrop");
const cartItemsElement = document.querySelector("#cart-items");
const cartCountElement = document.querySelector("#cart-count");
const cartSubtotal = document.querySelector("#cart-subtotal");
const cartEmpty = document.querySelector("#cart-empty");
const cartFooter = document.querySelector("#cart-footer");
const toast = document.querySelector("#toast");
const authDialog = document.querySelector("#auth-dialog");
const authBackdrop = document.querySelector("#auth-backdrop");
const authTrigger = document.querySelector("#auth-trigger");

let activeCategory = "Semua";
let cart = [];
let toastTimeout;
let currentUser = null;
let csrfToken = "";
let loginCountdownTimer = null;
let loginLockoutUntil = 0;
let registrationOtpResendTimer = null;
const checkoutDialog = document.querySelector("#checkout-dialog");

async function apiRequest(route, { method = "GET", body = null } = {}) {
  const headers = { Accept: "application/json" };
  if (body !== null) headers["Content-Type"] = "application/json";
  if (method !== "GET" && csrfToken) headers["X-CSRF-Token"] = csrfToken;

  const response = await fetch(
    `api/index.php?route=${encodeURIComponent(route)}`,
    {
      method,
      headers,
      credentials: "same-origin",
      body: body === null ? null : JSON.stringify(body),
    },
  );
  const result = await response.json();
  if (!response.ok) {
    const error = new Error(result.error || "Permintaan ke server gagal.");
    error.retryAfterSeconds = Number(result.retryAfterSeconds) || 0;
    error.remainingAttempts = Number(result.remainingAttempts) || 0;
    throw error;
  }
  if (result.csrfToken) csrfToken = result.csrfToken;
  return result;
}

function escapeHtml(value) {
  return String(value).replace(
    /[&<>"']/g,
    (character) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      })[character],
  );
}

async function initializeApp() {
  try {
    const session = await apiRequest("session");
    currentUser = session.user;
    updateAuthButton();
    await loadProducts();
  } catch (error) {
    emptyState.hidden = false;
    emptyState.textContent = `Koneksi database gagal: ${error.message} Jalankan website melalui XAMPP dan pastikan database sudah diimpor.`;
    showToast("Tidak dapat terhubung ke server GadgetHub.");
  }
}

async function loadProducts() {
  const catalog = await apiRequest("products");
  products = catalog.products.map((product) => ({
    ...product,
    id: Number(product.id),
    price: Number(product.price),
    rating: Number(product.rating),
    reviews: Number(product.reviews),
    stock: Number(product.stock),
  }));
  renderProducts();
}

function renderProducts() {
  const searchTerm = searchInput.value.trim().toLocaleLowerCase("id-ID");
  const filteredProducts = products.filter((product) => {
    const matchesCategory =
      activeCategory === "Semua" || product.category === activeCategory;
    const matchesSearch = `${product.name} ${product.category}`
      .toLocaleLowerCase("id-ID")
      .includes(searchTerm);
    return matchesCategory && matchesSearch;
  });

  productGrid.innerHTML = filteredProducts
    .map(
      (product) => `
    <article class="product-card">
      <div class="product-image-wrap">
        <img class="product-image" src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}" loading="lazy">
      </div>
      <div class="product-info">
        <span class="product-category">${escapeHtml(product.category)}</span>
        <h3 class="product-name">${escapeHtml(product.name)}</h3>
        <div class="product-rating" aria-label="Rating ${product.rating} dari 5">★ ${product.rating} <span>(${product.reviews} ulasan)</span></div>
        <div class="product-price-row">
          <div class="product-price">${formatRupiah(product.price)}</div>
          <button class="add-button" type="button" data-add="${product.id}" aria-label="Tambah ${escapeHtml(product.name)} ke keranjang" ${product.stock < 1 ? "disabled" : ""}>${product.stock < 1 ? "−" : "+"}</button>
        </div>
      </div>
    </article>
  `,
    )
    .join("");

  resultCount.textContent = `${filteredProducts.length} produk`;
  emptyState.hidden = filteredProducts.length > 0;
}

function renderCart() {
  const itemCount = cart.reduce((total, item) => total + item.quantity, 0);
  const subtotal = cart.reduce(
    (total, item) => total + item.product.price * item.quantity,
    0,
  );

  cartCountElement.textContent = itemCount;
  document.querySelector("#cart-title-count").textContent = `(${itemCount})`;
  cartSubtotal.textContent = formatRupiah(subtotal);
  cartEmpty.hidden = cart.length > 0;
  cartFooter.hidden = cart.length === 0;
  cartItemsElement.innerHTML = cart
    .map(
      ({ product, quantity }) => `
    <article class="cart-item">
      <img src="${escapeHtml(product.image)}" alt="">
      <div>
        <div class="cart-item-name">${escapeHtml(product.name)}</div>
        <div class="cart-item-price">${formatRupiah(product.price)}</div>
        <div class="quantity-controls" aria-label="Jumlah ${product.name}">
          <button type="button" data-quantity="${product.id}" data-change="-1" aria-label="Kurangi jumlah">−</button>
          <span>${quantity}</span>
          <button type="button" data-quantity="${product.id}" data-change="1" aria-label="Tambah jumlah" ${quantity >= product.stock ? "disabled" : ""}>+</button>
        </div>
      </div>
      <button class="remove-item" type="button" data-remove="${product.id}" aria-label="Hapus ${escapeHtml(product.name)}">×</button>
    </article>
  `,
    )
    .join("");
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("visible");
  window.clearTimeout(toastTimeout);
  toastTimeout = window.setTimeout(
    () => toast.classList.remove("visible"),
    2400,
  );
}

function setAuthError(formName, message) {
  const error = document.querySelector(`#${formName}-error`);
  error.textContent = message;
  error.hidden = !message;
}

function startLoginCountdown(seconds) {
  const countdownElement = document.querySelector("#login-countdown");
  const errorElement = document.querySelector("#login-error");
  const submitButton = document.querySelector('#login-form [type="submit"]');
  loginLockoutUntil = Date.now() + Math.max(0, Math.ceil(seconds)) * 1000;
  window.clearInterval(loginCountdownTimer);
  errorElement.hidden = true;
  countdownElement.hidden = false;

  const updateCountdown = () => {
    const remaining = Math.max(
      0,
      Math.ceil((loginLockoutUntil - Date.now()) / 1000),
    );
    submitButton.disabled = remaining > 0;
    if (remaining <= 0) {
      window.clearInterval(loginCountdownTimer);
      loginCountdownTimer = null;
      loginLockoutUntil = 0;
      submitButton.disabled = false;
      countdownElement.textContent =
        "Masa suspend berakhir. Silakan coba masuk kembali.";
      window.setTimeout(() => {
        if (!submitButton.disabled) countdownElement.hidden = true;
      }, 4000);
      return;
    }
    countdownElement.textContent = `Terlalu banyak percobaan gagal. Kamu bisa mencoba dalam ${remaining} detik.`;
  };

  updateCountdown();
  loginCountdownTimer = window.setInterval(updateCountdown, 1000);
}

function setAuthMode(mode) {
  const isLogin = mode === "login";
  const isRegister = mode === "register";
  const formByMode = {
    login: "#login-form",
    "login-otp": "#login-otp-form",
    verification: "#verification-form",
    register: "#register-form",
    forgot: "#forgot-password-form",
    reset: "#reset-password-form",
    "change-password": "#change-password-form",
  };
  Object.values(formByMode).forEach((selector) => {
    document.querySelector(selector).hidden = selector !== formByMode[mode];
  });
  document.querySelector(".auth-tabs").hidden = !["login", "register"].includes(mode);
  const introByMode = {
    login: "Selamat datang di GadgetHub. Masuk untuk melanjutkan.",
    register:
      "Daftar akun baru. Kami akan mengirim kode verifikasi ke email kamu.",
    "login-otp": "Verifikasi keamanan tambahan untuk melanjutkan login.",
    verification: "Verifikasi alamat email untuk menyelesaikan pendaftaran.",
    forgot: "Pulihkan akses akun GadgetHub kamu.",
    reset:
      "Jika email terdaftar, kode konfirmasi akan dikirim. Masukkan kode dan buat kata sandi baru di sini.",
    "change-password": currentUser
      ? `Perbarui keamanan akun ${currentUser.name}.`
      : "Silakan masuk untuk mengganti kata sandi.",
  };
  document.querySelector("#auth-intro").textContent = introByMode[mode];
  document.querySelector("#login-tab").classList.toggle("selected", isLogin);
  document
    .querySelector("#register-tab")
    .classList.toggle("selected", isRegister);
  document
    .querySelector("#login-tab")
    .setAttribute("aria-selected", String(isLogin));
  document
    .querySelector("#register-tab")
    .setAttribute("aria-selected", String(isRegister));
  [
    "login",
    "login-otp",
    "register",
    "forgot-password",
    "reset-password",
    "change-password",
    "verification",
  ].forEach((formName) => setAuthError(formName, ""));
  document.querySelector("#login-countdown").hidden = true;
  if (isLogin && loginLockoutUntil > Date.now()) {
    startLoginCountdown(Math.ceil((loginLockoutUntil - Date.now()) / 1000));
  }
  const focusByMode = {
    login: "#login-email",
    "login-otp": "#login-otp-code",
    verification: "#verification-otp",
    register: "#register-name",
    forgot: "#forgot-password-email",
    reset: "#reset-password-new",
    "change-password": "#change-password-current",
  };
  document.querySelector(focusByMode[mode]).focus();
}

function openAuth(mode = currentUser ? "change-password" : "login") {
  authDialog.classList.add("open");
  authBackdrop.classList.add("visible");
  authDialog.setAttribute("aria-hidden", "false");
  authDialog.removeAttribute("inert");
  document.body.classList.add("auth-open");
  setAuthMode(mode);
}

function closeAuth() {
  authDialog.classList.remove("open");
  authBackdrop.classList.remove("visible");
  authDialog.setAttribute("aria-hidden", "true");
  authDialog.setAttribute("inert", "");
  document.body.classList.remove("auth-open");
  authTrigger.focus();
}

function updateAuthButton() {
  authTrigger.textContent = currentUser
    ? `Akun · ${currentUser.name}`
    : "Masuk";
  authTrigger.classList.toggle("signed-in", Boolean(currentUser));
  authTrigger.setAttribute(
    "aria-label",
    currentUser
      ? `Ganti kata sandi akun ${currentUser.name}`
      : "Masuk atau daftar akun",
  );
  const isAdmin = ["admin", "super_admin"].includes(currentUser?.role);
  document.querySelector("#admin-nav-link").hidden = !isAdmin;
  document.querySelector("#admin-nav-link").textContent =
    currentUser?.role === "super_admin"
      ? "Pusat kontrol super admin ↗"
      : "Dashboard admin ↗";
  document.querySelector("#admin-nav-link").href =
    currentUser?.role === "super_admin"
      ? "super_admin/"
      : "admin/";
}

document
  .querySelector("#login-tab")
  .addEventListener("click", () => setAuthMode("login"));
document
  .querySelector("#register-tab")
  .addEventListener("click", () => setAuthMode("register"));
document
  .querySelector("#forgot-password-link")
  .addEventListener("click", () => setAuthMode("forgot"));
document
  .querySelector("#back-to-login")
  .addEventListener("click", () => setAuthMode("login"));
document
  .querySelector("#back-to-login-otp")
  .addEventListener("click", () => setAuthMode("login"));
document.querySelector("#request-reset-again").addEventListener("click", () => {
  document.querySelector("#forgot-password-form").elements.email.value =
    document.querySelector("#reset-password-email").value;
  setAuthMode("forgot");
});
document.querySelector("#auth-close").addEventListener("click", closeAuth);
authBackdrop.addEventListener("click", closeAuth);
authTrigger.addEventListener("click", () => {
  if (!currentUser) {
    openAuth();
    return;
  }
  openAuth("change-password");
});

document
  .querySelector("#forgot-password-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("forgot-password", "");
    submitButton.disabled = true;
    try {
      const email = form.elements.email.value.trim();
      const result = await apiRequest("password-reset-request", {
        method: "POST",
        body: { email },
      });
      document.querySelector("#reset-password-email").value = email;
      form.reset();
      setAuthMode("reset");
    } catch (error) {
      setAuthError("forgot-password", error.message);
    } finally {
      submitButton.disabled = false;
    }
  });

document
  .querySelector("#reset-password-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("reset-password", "");
    if (form.elements.password.value !== form.elements.confirm.value) {
      setAuthError("reset-password", "Konfirmasi kata sandi tidak sama.");
      form.elements.confirm.focus();
      return;
    }
    submitButton.disabled = true;
    try {
      const result = await apiRequest("password-reset", {
        method: "POST",
        body: {
          email: form.elements.email.value,
          code: form.elements.code.value.trim(),
          password: form.elements.password.value,
        },
      });
      form.reset();
      setAuthMode("login");
      closeAuth();
      showToast(result.message);
    } catch (error) {
      setAuthError("reset-password", error.message);
    } finally {
      submitButton.disabled = false;
    }
  });

document
  .querySelector("#change-password-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("change-password", "");
    if (form.elements.newPassword.value !== form.elements.confirm.value) {
      setAuthError("change-password", "Konfirmasi kata sandi tidak sama.");
      form.elements.confirm.focus();
      return;
    }
    submitButton.disabled = true;
    try {
      const result = await apiRequest("change-password", {
        method: "POST",
        body: {
          currentPassword: form.elements.currentPassword.value,
          newPassword: form.elements.newPassword.value,
        },
      });
      form.reset();
      closeAuth();
      showToast(result.message);
    } catch (error) {
      setAuthError("change-password", error.message);
    } finally {
      submitButton.disabled = false;
    }
  });

document.querySelector("#account-logout").addEventListener("click", async () => {
  const button = document.querySelector("#account-logout");
  button.disabled = true;
  try {
    await apiRequest("logout", { method: "POST", body: {} });
    currentUser = null;
    updateAuthButton();
    closeAuth();
    showToast("Kamu sudah keluar dari akun.");
  } catch (error) {
    setAuthError(
      "change-password",
      `Tidak dapat keluar dari akun: ${error.message}`,
    );
  } finally {
    button.disabled = false;
  }
});

document
  .querySelector("#register-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("register", "");

    if (form.elements.password.value !== form.elements.confirm.value) {
      setAuthError("register", "Konfirmasi kata sandi tidak sama.");
      form.elements.confirm.focus();
      return;
    }

    submitButton.disabled = true;
    try {
      const result = await apiRequest("register", {
        method: "POST",
        body: {
          name: form.elements.name.value.trim(),
          email: form.elements.email.value.trim(),
          password: form.elements.password.value,
        },
      });

      document.querySelector("#verification-email").value = result.email;
      document.querySelector("#verification-email-label").textContent =
        result.email;
      document.querySelector("#verification-expiry").textContent =
        `Kode berlaku ${result.expiresInMinutes} menit.`;
      const otpInput = document.querySelector("#verification-otp");
      otpInput.maxLength = result.codeLength;
      otpInput.pattern = `[0-9]{${result.codeLength}}`;
      otpInput.placeholder = "0".repeat(result.codeLength);
      otpInput.value = "";
      const resendButton = document.querySelector("#resend-otp");
      window.clearTimeout(registrationOtpResendTimer);
      resendButton.disabled = Number(result.resendAfterSeconds) > 0;
      if (resendButton.disabled) {
        registrationOtpResendTimer = window.setTimeout(() => {
          resendButton.disabled = false;
        }, Number(result.resendAfterSeconds) * 1000);
      }
      setAuthMode("verification");
      document.querySelector("#auth-intro").textContent = result.message;
      form.reset();
      showToast(result.message);
    } catch (error) {
      setAuthError("register", `Pendaftaran gagal: ${error.message}`);
    } finally {
      submitButton.disabled = false;
    }
  });

document
  .querySelector("#verification-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    const error = document.querySelector("#verification-error");
    error.hidden = true;
    submitButton.disabled = true;
    try {
      const result = await apiRequest("verify-registration", {
        method: "POST",
        body: {
          email: form.elements.email.value,
          otp: form.elements.otp.value.trim(),
        },
      });
      currentUser = result.user;
      updateAuthButton();
      closeAuth();
      form.reset();
      showToast(`Email terverifikasi. Selamat datang, ${currentUser.name}!`);
    } catch (requestError) {
      error.textContent = requestError.message;
      error.hidden = false;
    } finally {
      if (!loginCountdownTimer) submitButton.disabled = false;
    }
  });

document
  .querySelector("#resend-otp")
  .addEventListener("click", async (event) => {
    const button = event.currentTarget;
    const error = document.querySelector("#verification-error");
    error.hidden = true;
    button.disabled = true;
    try {
      const result = await apiRequest("resend-registration-otp", {
        method: "POST",
        body: { email: document.querySelector("#verification-email").value },
      });
      showToast(result.message);
    } catch (requestError) {
      error.textContent = requestError.message;
      error.hidden = false;
    } finally {
      button.disabled = false;
    }
  });

document
  .querySelector("#login-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("login", "");
    submitButton.disabled = true;

    try {
      const result = await apiRequest("login", {
        method: "POST",
        body: {
          email: form.elements.email.value.trim(),
          password: form.elements.password.value,
        },
      });

      const email = form.elements.email.value.trim();
      document.querySelector("#login-otp-email").value = result.email || email;
      document.querySelector("#login-otp-email-label").textContent =
        result.email || email;
      document.querySelector("#login-otp-expiry").textContent =
        `Kode berlaku ${result.expiresInMinutes} menit.`;
      const otpInput = document.querySelector("#login-otp-code");
      otpInput.maxLength = result.codeLength;
      otpInput.pattern = `[0-9]{${result.codeLength}}`;
      otpInput.placeholder = "0".repeat(result.codeLength);
      otpInput.value = "";
      form.elements.password.value = "";
      setAuthMode("login-otp");
      document.querySelector("#auth-intro").textContent = result.message;
      showToast(result.message);
    } catch (error) {
      if (error.retryAfterSeconds > 0) {
        startLoginCountdown(error.retryAfterSeconds);
      } else {
        document.querySelector("#login-countdown").hidden = true;
        const attemptsMessage =
          error.remainingAttempts > 0
            ? ` Sisa percobaan: ${error.remainingAttempts}.`
            : "";
        setAuthError(
          "login",
          `Tidak dapat masuk: ${error.message}${attemptsMessage}`,
        );
      }
    } finally {
      if (!loginCountdownTimer) submitButton.disabled = false;
    }
  });

document
  .querySelector("#login-otp-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const submitButton = form.querySelector('[type="submit"]');
    setAuthError("login-otp", "");
    submitButton.disabled = true;
    try {
      const result = await apiRequest("verify-login-otp", {
        method: "POST",
        body: {
          email: form.elements.email.value,
          otp: form.elements.otp.value.trim(),
        },
      });

      currentUser = result.user;
      updateAuthButton();
      form.reset();
      document.querySelector("#login-form").reset();
      closeAuth();
      showToast(`Selamat datang kembali, ${currentUser.name}!`);
    } catch (error) {
      setAuthError("login-otp", error.message);
    } finally {
      submitButton.disabled = false;
    }
  });

document
  .querySelector("#resend-login-otp")
  .addEventListener("click", async (event) => {
    const button = event.currentTarget;
    let waitBeforeRetry = false;
    setAuthError("login-otp", "");
    button.disabled = true;
    try {
      const result = await apiRequest("resend-login-otp", {
        method: "POST",
        body: { email: document.querySelector("#login-otp-email").value },
      });
      showToast(result.message);
    } catch (error) {
      setAuthError("login-otp", error.message);
      if (error.retryAfterSeconds > 0) {
        waitBeforeRetry = true;
        window.setTimeout(() => {
          button.disabled = false;
        }, error.retryAfterSeconds * 1000);
      }
    } finally {
      if (!waitBeforeRetry) button.disabled = false;
    }
  });

function openCart() {
  cartDrawer.classList.add("open");
  cartBackdrop.classList.add("visible");
  cartDrawer.setAttribute("aria-hidden", "false");
  document.body.classList.add("cart-open");
  document.querySelector("#close-cart").focus();
}

function closeCart() {
  cartDrawer.classList.remove("open");
  cartBackdrop.classList.remove("visible");
  cartDrawer.setAttribute("aria-hidden", "true");
  document.body.classList.remove("cart-open");
  document.querySelector("#cart-trigger").focus();
}

function setCategory(category) {
  activeCategory = category;
  document.querySelectorAll(".filter-chip").forEach((chip) => {
    const isSelected = chip.dataset.filter === category;
    chip.classList.toggle("selected", isSelected);
    chip.setAttribute("aria-pressed", String(isSelected));
  });
  renderProducts();
  document
    .querySelector("#produk")
    .scrollIntoView({ behavior: "smooth", block: "start" });
}

productGrid.addEventListener("click", (event) => {
  const button = event.target.closest("[data-add]");
  if (!button) return;

  const product = products.find(
    (item) => item.id === Number(button.dataset.add),
  );
  if (!product || product.stock < 1) return;
  const existingItem = cart.find((item) => item.product.id === product.id);
  if (existingItem && existingItem.quantity >= product.stock) {
    showToast("Jumlah di keranjang sudah mencapai stok yang tersedia.");
    return;
  }
  if (existingItem) existingItem.quantity += 1;
  else cart.push({ product, quantity: 1 });
  renderCart();
  showToast(`${product.name} ditambahkan ke keranjang`);
});

cartItemsElement.addEventListener("click", (event) => {
  const quantityButton = event.target.closest("[data-quantity]");
  const removeButton = event.target.closest("[data-remove]");

  if (quantityButton) {
    const id = Number(quantityButton.dataset.quantity);
    const item = cart.find((entry) => entry.product.id === id);
    const quantityChange = Number(quantityButton.dataset.change);
    if (quantityChange > 0 && item.quantity >= item.product.stock) {
      showToast("Stok produk tidak mencukupi.");
      return;
    }
    item.quantity += quantityChange;
    if (item.quantity <= 0)
      cart = cart.filter((entry) => entry.product.id !== id);
    renderCart();
  }

  if (removeButton) {
    cart = cart.filter(
      (entry) => entry.product.id !== Number(removeButton.dataset.remove),
    );
    renderCart();
  }
});

document.querySelectorAll(".filter-chip").forEach((chip) => {
  chip.setAttribute(
    "aria-pressed",
    String(chip.classList.contains("selected")),
  );
  chip.addEventListener("click", () => setCategory(chip.dataset.filter));
});

document.querySelectorAll(".category-card").forEach((card) => {
  card.addEventListener("click", () => setCategory(card.dataset.category));
});

searchInput.addEventListener("input", renderProducts);
document.querySelector("#reset-filter").addEventListener("click", (event) => {
  event.preventDefault();
  activeCategory = "Semua";
  searchInput.value = "";
  document.querySelectorAll(".filter-chip").forEach((chip) => {
    const isSelected = chip.dataset.filter === "Semua";
    chip.classList.toggle("selected", isSelected);
    chip.setAttribute("aria-pressed", String(isSelected));
  });
  renderProducts();
  document.querySelector("#produk").scrollIntoView({ behavior: "smooth" });
});

document.querySelector("#cart-trigger").addEventListener("click", openCart);
document.querySelector("#close-cart").addEventListener("click", closeCart);
cartBackdrop.addEventListener("click", closeCart);
document
  .querySelector("#continue-shopping")
  .addEventListener("click", closeCart);
document.querySelector("#checkout-button").addEventListener("click", () => {
  if (!currentUser) {
    openAuth();
    showToast("Silakan masuk untuk mengisi informasi pemesan.");
    return;
  }
  if (cart.length === 0) return;
  const form = document.querySelector("#checkout-form");
  form.elements.recipientName.value = currentUser.name || "";
  form.elements.recipientEmail.value = currentUser.email || "";
  form.elements.paymentMethod.value = "";
  document.querySelector("#checkout-item-summary").textContent =
    `${cart.reduce((total, item) => total + item.quantity, 0)} barang`;
  document.querySelector("#checkout-total").textContent = formatRupiah(
    cart.reduce((total, item) => total + item.product.price * item.quantity, 0),
  );
  document.querySelector("#checkout-error").hidden = true;
  document.querySelector("#checkout-success").hidden = true;
  form.hidden = false;
  closeCart();
  checkoutDialog.showModal();
  form.elements.recipientName.focus();
});

document.querySelector("#checkout-close").addEventListener("click", () => {
  checkoutDialog.close();
});
document.querySelector("#checkout-done").addEventListener("click", () => {
  checkoutDialog.close();
});
checkoutDialog.addEventListener("click", (event) => {
  if (event.target === checkoutDialog) checkoutDialog.close();
});
checkoutDialog.addEventListener("close", () => {
  const form = document.querySelector("#checkout-form");
  form.hidden = false;
  document.querySelector("#checkout-success").hidden = true;
});

document
  .querySelector("#checkout-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!currentUser || cart.length === 0) return;
    const form = event.currentTarget;
    const submitButton = document.querySelector("#checkout-submit");
    const errorElement = document.querySelector("#checkout-error");
    errorElement.hidden = true;
    submitButton.disabled = true;
    try {
      const result = await apiRequest("orders", {
        method: "POST",
        body: {
          recipientName: form.elements.recipientName.value.trim(),
          recipientEmail: form.elements.recipientEmail.value.trim(),
          recipientPhone: form.elements.recipientPhone.value.trim(),
          shippingAddress: form.elements.shippingAddress.value.trim(),
          paymentMethod: form.elements.paymentMethod.value,
          items: cart.map(({ product, quantity }) => ({
            productId: product.id,
            quantity,
          })),
        },
      });
      cart = [];
      renderCart();
      form.hidden = true;
      const paymentMethodLabel =
        result.paymentMethod === "cod" ? "COD" : "Transfer bank";
      document.querySelector("#checkout-success-message").textContent =
        result.paymentMethod === "cod"
          ? `Terima kasih, ${result.recipientName}. Pesananmu sudah tercatat. Pembayaran dilakukan saat barang diterima.`
          : `Terima kasih, ${result.recipientName}. Pesananmu sudah tercatat. Silakan hubungi admin untuk mendapatkan informasi rekening transfer.`;
      document.querySelector("#checkout-success-details").innerHTML =
        `<span>Nomor pesanan<strong>#${Number(result.orderId)}</strong></span><span>Total<strong>${formatRupiah(Number(result.total))}</strong></span><span>Metode pembayaran<strong>${paymentMethodLabel}</strong></span><span>Status<strong>Menunggu pembayaran</strong></span>`;
      document.querySelector("#checkout-success").hidden = false;
      try {
        await loadProducts();
      } catch (error) {
        showToast(
          `Pesanan tersimpan, tetapi stok belum diperbarui: ${error.message}`,
        );
      }
    } catch (error) {
      errorElement.textContent = `Pesanan gagal dibuat: ${error.message}`;
      errorElement.hidden = false;
    } finally {
      submitButton.disabled = false;
    }
  });
document.querySelector("#menu-toggle").addEventListener("click", (event) => {
  const menu = document.querySelector(".main-nav");
  const isOpen = menu.classList.toggle("open");
  event.currentTarget.setAttribute("aria-expanded", String(isOpen));
});
document.querySelectorAll(".nav-link").forEach((link) => {
  link.addEventListener("click", () => {
    document.querySelector(".main-nav").classList.remove("open");
    document
      .querySelector("#menu-toggle")
      .setAttribute("aria-expanded", "false");
  });
});
document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && cartDrawer.classList.contains("open"))
    closeCart();
  if (event.key === "Escape" && authDialog.classList.contains("open"))
    closeAuth();
  if (
    event.key === "/" &&
    !["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)
  ) {
    event.preventDefault();
    searchInput.focus();
  }
});

renderCart();
initializeApp();
