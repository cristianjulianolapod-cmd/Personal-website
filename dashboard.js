let currentUser = null;
let csrfToken = "";
let toastTimeout;
let dashboardOrders = [];
const routeRole = window.location.pathname.replace(/\/+$/, "").split("/").pop();
const requestedDashboardRole =
  ["admin", "super_admin"].includes(routeRole)
    ? routeRole
    : new URLSearchParams(window.location.search).get("role");
let adminLoginEmail = "";
let otpResendTimer = null;

const paymentMethodLabels = {
  cod: "COD",
  bank_transfer: "Transfer bank",
};

const formatRupiah = (amount) =>
  new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0,
  }).format(amount);

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

function showToast(message) {
  const toast = document.querySelector("#toast");
  toast.textContent = message;
  toast.classList.add("visible");
  window.clearTimeout(toastTimeout);
  toastTimeout = window.setTimeout(
    () => toast.classList.remove("visible"),
    2800,
  );
}

function showAccessMessage(message, isError = false) {
  const element = document.querySelector("#admin-access-message");
  element.textContent = message;
  element.classList.toggle("error", isError);
  element.hidden = false;
  document.querySelector("#admin-login").hidden = true;
}

function showAdminLogin() {
  const isSpecificRole = requestedDashboardRole !== null;
  const roleLabel = requestedDashboardRole === "super_admin"
    ? "super admin"
    : requestedDashboardRole === "admin"
      ? "admin"
      : "admin atau super admin";
  document.querySelector("#admin-access-message").hidden = true;
  document.querySelector("#admin-login").hidden = false;
  document.querySelector("#admin-login-title").textContent =
    isSpecificRole
      ? `Masuk ${roleLabel}`
      : "Masuk administrator";
  document.querySelector("#admin-login-intro").textContent =
    `Halaman ini hanya menerima akun ${roleLabel} GadgetHub. Kode OTP akan dikirim setelah kata sandi benar.`;
  document.querySelector("#admin-login-form").hidden = false;
  document.querySelector("#admin-login-otp-form").hidden = true;
  document.querySelector("#admin-login-email").focus();
}

function setAdminLoginError(formId, message) {
  const error = document.querySelector(`#${formId}-error`);
  error.textContent = message;
  error.hidden = !message;
}

function startOtpResendCooldown(seconds) {
  const button = document.querySelector("#admin-login-resend");
  window.clearTimeout(otpResendTimer);
  button.disabled = seconds > 0;
  if (seconds > 0) {
    otpResendTimer = window.setTimeout(() => {
      button.disabled = false;
    }, seconds * 1000);
  }
}

async function loadAdminDashboard() {
  const data = await apiRequest("admin-dashboard");
  const isSuperAdmin = currentUser.role === "super_admin";
  document.querySelector("#admin-dashboard").hidden = false;
  document.querySelector("#admin-access-message").hidden = true;
  document.querySelector("#admin-login").hidden = true;
  document.querySelector("#admin-logout").hidden = false;
  document.querySelector("#super-admin-transactions-panel").hidden =
    !isSuperAdmin;
  [
    "#super-admin-marketing-panel",
    "#super-admin-reports-panel",
    "#super-admin-moderation-panel",
  ].forEach((selector) => {
    document.querySelector(selector).hidden = !isSuperAdmin;
  });
  document.querySelector("#admin-standard-heading").hidden = isSuperAdmin;
  document.querySelector("#super-admin-overview").hidden = !isSuperAdmin;
  document.querySelector("#super-admin-name").textContent = currentUser.name;
  document.querySelector("#admin-role-badge").textContent = isSuperAdmin
    ? "Super Administrator"
    : "Administrator";

  const stats = isSuperAdmin
    ? [
        ["Total akun", Number(data.stats.userCount).toLocaleString("id-ID")],
        [
          "Administrator",
          Number(data.stats.adminCount).toLocaleString("id-ID"),
        ],
        [
          "Akun nonaktif",
          Number(data.stats.inactiveUserCount).toLocaleString("id-ID"),
        ],
        [
          "Produk aktif",
          Number(data.stats.productCount).toLocaleString("id-ID"),
        ],
      ]
    : [
        [
          "Total pengguna",
          Number(data.stats.userCount).toLocaleString("id-ID"),
        ],
        [
          "Produk aktif",
          Number(data.stats.productCount).toLocaleString("id-ID"),
        ],
        [
          "Pesanan berjalan",
          Number(data.stats.openOrderCount).toLocaleString("id-ID"),
        ],
        ["Pendapatan tercatat", formatRupiah(Number(data.stats.revenue))],
      ];
  document
    .querySelector("#admin-stats")
    .classList.toggle("super-admin-stats", isSuperAdmin);
  document.querySelector("#admin-stats").innerHTML = stats
    .map(
      ([label, value]) =>
        `<article class="admin-stat"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></article>`,
    )
    .join("");

  document.querySelector("#admin-product-create").hidden = !isSuperAdmin;
  const categories = [
    ...new Set(data.products.map((product) => product.category)),
  ];
  document.querySelector("#admin-product-categories").innerHTML = categories
    .map((category) => `<option value="${escapeHtml(category)}"></option>`)
    .join("");

  const statusLabels = {
    pending: "Menunggu",
    paid: "Dibayar",
    processing: "Diproses",
    shipped: "Dikirim",
    completed: "Selesai",
    cancelled: "Dibatalkan",
  };
  dashboardOrders = data.orders;
  document.querySelector("#admin-orders").innerHTML = dashboardOrders.length
    ? dashboardOrders
        .map(
          (order) => `
      <tr data-order-id="${Number(order.id)}" data-order-status="${escapeHtml(order.status)}" data-search="${escapeHtml(`#${Number(order.id)} ORD-${Number(order.id)} ${order.customer} ${order.email} ${order.phone} ${order.itemsSummary}`.toLocaleLowerCase("id-ID"))}">
        <td><strong>#${Number(order.id)}</strong><small>ORD-${Number(order.id)}</small></td>
        <td><strong>${escapeHtml(order.customer)}</strong><small>${escapeHtml(order.email)}${order.phone ? ` · ${escapeHtml(order.phone)}` : ""}</small></td>
        <td class="admin-order-items">${escapeHtml(order.itemsSummary || "Rincian produk tidak tersedia")}</td>
        <td class="admin-order-address">${escapeHtml(order.shippingAddress)}</td>
        <td>${escapeHtml(new Date(order.createdAt).toLocaleDateString("id-ID"))}</td>
        <td>${formatRupiah(Number(order.total))}</td>
        <td>${escapeHtml(paymentMethodLabels[order.paymentMethod] || "Belum ditentukan")}</td>
        <td><select class="admin-select" data-order-status="${Number(order.id)}" aria-label="Status pesanan #${Number(order.id)}" ${currentUser.role === "super_admin" ? "" : "disabled"}>
          ${Object.entries(statusLabels)
            .map(
              ([value, label]) =>
                `<option value="${value}"${order.status === value ? " selected" : ""}>${label}</option>`,
            )
            .join("")}
        </select></td>
      </tr>
    `,
        )
        .join("")
    : '<tr><td class="admin-empty" colspan="8">Belum ada pesanan.</td></tr>';

  renderOrderFilters();
  renderTransactions(statusLabels, data.stats);
  if (isSuperAdmin) {
    renderTransactionActivities(data.transactionActivities || []);
    renderAdminTools(data.management, data.reports, data.products);
  }

  document.querySelector("#admin-products").innerHTML = data.products.length
    ? data.products
        .map((product) => {
          const id = Number(product.id);
          const name = escapeHtml(product.name);
          return `
          <tr data-product-row="${id}">
            <td>${
              isSuperAdmin
                ? `<input class="admin-product-input" data-product-field="name" type="text" maxlength="180" value="${name}" aria-label="Nama ${name}" required>`
                : `<strong>${name}</strong>`
            }</td>
            <td>${
              isSuperAdmin
                ? `<input class="admin-product-input" data-product-field="category" type="text" maxlength="60" list="admin-product-categories" value="${escapeHtml(product.category)}" aria-label="Kategori ${name}" required>`
                : escapeHtml(product.category)
            }</td>
            <td>${
              isSuperAdmin
                ? `<input class="admin-product-input admin-number-input" data-product-field="price" type="number" min="0" max="4294967295" value="${Number(product.price)}" aria-label="Harga ${name}" required>`
                : formatRupiah(Number(product.price))
            }</td>
            <td>${
              isSuperAdmin
                ? `<input class="admin-product-input admin-image-input" data-product-field="image" type="url" maxlength="500" value="${escapeHtml(product.image)}" aria-label="URL gambar ${name}" required>`
                : `<a class="admin-product-image-link" href="${escapeHtml(product.image)}" target="_blank" rel="noopener noreferrer">Lihat gambar</a>`
            }</td>
            <td><input class="admin-stock-input" type="number" min="0" max="1000000" value="${Number(product.stock)}" data-product-stock="${id}" aria-label="Stok ${name}"></td>
            <td>${
              isSuperAdmin
                ? `<label class="admin-check"><input type="checkbox" data-product-field="isActive"${Number(product.isActive) ? " checked" : ""}> Aktif</label>`
                : Number(product.isActive)
                  ? "Aktif"
                  : "Nonaktif"
            }</td>
            <td>${
              isSuperAdmin
                ? `<button class="admin-action-button" type="button" data-save-product="${id}">Simpan</button>`
                : "—"
            }</td>
          </tr>`;
        })
        .join("")
    : '<tr><td class="admin-empty" colspan="7">Belum ada produk.</td></tr>';

  document.querySelector("#admin-users").innerHTML = data.users.length
    ? data.users
        .map((user) => {
          const userId = Number(user.id);
          const isCurrentUser = userId === Number(currentUser.id);
          const isProtected = user.role === "super_admin";
          const roleText =
            user.role === "super_admin"
              ? "Super admin"
              : user.role === "admin"
                ? "Admin"
                : "User";
          const roleControl =
            isSuperAdmin && !isCurrentUser && !isProtected
              ? `<select class="admin-select" data-user-role="${userId}" aria-label="Peran ${escapeHtml(user.name)}"><option value="user"${user.role === "user" ? " selected" : ""}>User</option><option value="admin"${user.role === "admin" ? " selected" : ""}>Admin</option></select>`
              : `<span class="admin-badge">${escapeHtml(roleText)}${isCurrentUser ? " · Anda" : ""}</span>`;
          const statusControl =
            isSuperAdmin && !isCurrentUser && !isProtected
              ? `<select class="admin-select" data-user-active="${userId}" aria-label="Status akun ${escapeHtml(user.name)}"><option value="1"${Number(user.isActive) ? " selected" : ""}>Aktif</option><option value="0"${Number(user.isActive) ? "" : " selected"}>Nonaktif</option></select>`
              : `<span class="admin-badge">${Number(user.isActive) ? "Aktif" : "Nonaktif"}</span>`;
          return `<tr>
          <td><strong>${escapeHtml(user.name)}</strong></td>
          <td>${escapeHtml(user.email)}</td>
          <td>${escapeHtml(new Date(user.createdAt).toLocaleDateString("id-ID"))}</td>
          <td>${roleControl}</td>
          <td>${statusControl}</td>
        </tr>`;
        })
        .join("")
    : '<tr><td class="admin-empty" colspan="5">Belum ada akun.</td></tr>';
}

function renderOrderFilters() {
  const searchTerm = document
    .querySelector("#admin-order-search")
    .value.trim()
    .toLocaleLowerCase("id-ID");
  const selectedStatus = document.querySelector("#admin-order-filter").value;
  let visibleCount = 0;
  document
    .querySelectorAll("#admin-orders tr[data-order-id]")
    .forEach((row) => {
      const matchesStatus =
        selectedStatus === "all" || row.dataset.orderStatus === selectedStatus;
      const matchesSearch =
        !searchTerm || row.dataset.search.includes(searchTerm);
      row.hidden = !(matchesStatus && matchesSearch);
      if (!row.hidden) visibleCount++;
    });
  document.querySelector("#admin-order-count").textContent =
    `${visibleCount} dari ${dashboardOrders.length} pesanan`;
}

function renderTransactions(statusLabels, statsData) {
  const paymentLabels = {
    pending: "Menunggu",
    paid: "Dibayar",
    failed: "Gagal",
    refunded: "Dikembalikan",
  };
  const stats = [
    [
      "Pembayaran terkonfirmasi",
      Number(statsData.transactionCount).toLocaleString("id-ID"),
    ],
    [
      "Menunggu pembayaran",
      Number(statsData.pendingPaymentCount).toLocaleString("id-ID"),
    ],
    [
      "Gagal / dikembalikan",
      Number(statsData.failedPaymentCount).toLocaleString("id-ID"),
    ],
    ["Nilai terkonfirmasi", formatRupiah(Number(statsData.revenue))],
  ];
  document.querySelector("#admin-transaction-stats").innerHTML = stats
    .map(
      ([label, value]) =>
        `<article class="transaction-stat"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></article>`,
    )
    .join("");

  const transactions = dashboardOrders;
  document.querySelector("#admin-transactions").innerHTML = transactions.length
    ? transactions
        .map((order) => {
          const paymentStatus =
            paymentLabels[order.paymentStatus] || "Tidak diketahui";
          const paymentClass =
            order.paymentStatus === "paid"
              ? "confirmed"
              : order.paymentStatus === "pending"
                ? "waiting"
                : "failed";
          const updatedAt = order.paymentStatusUpdatedAt
            ? new Date(
                String(order.paymentStatusUpdatedAt).replace(" ", "T"),
              ).toLocaleString("id-ID")
            : "Belum diperbarui";
          return `<tr>
          <td><strong>ORD-${Number(order.id)}</strong><small>Pesanan #${Number(order.id)}</small></td>
          <td><strong>${escapeHtml(order.customer)}</strong><small>${escapeHtml(order.email)}${order.phone ? ` · ${escapeHtml(order.phone)}` : ""}</small></td>
          <td>${escapeHtml(new Date(order.createdAt).toLocaleDateString("id-ID"))}</td>
          <td><strong>${formatRupiah(Number(order.total))}</strong></td>
          <td>${escapeHtml(paymentMethodLabels[order.paymentMethod] || "Belum ditentukan")}</td>
          <td>${
            currentUser.role === "super_admin"
              ? `<select class="admin-select" data-payment-status="${Number(order.id)}" aria-label="Status pembayaran pesanan #${Number(order.id)}">
                ${Object.entries(paymentLabels)
                  .map(
                    ([value, label]) =>
                      `<option value="${value}"${order.paymentStatus === value ? " selected" : ""}>${label}</option>`,
                  )
                  .join("")}
              </select>`
              : `<span class="transaction-status ${paymentClass}">${escapeHtml(paymentStatus)}</span>`
          }</td>
          <td>${escapeHtml(statusLabels[order.status] || order.status)}</td>
          <td>${escapeHtml(updatedAt)}${order.paymentStatusUpdatedBy ? `<small>oleh ${escapeHtml(order.paymentStatusUpdatedBy)}</small>` : ""}</td>
        </tr>`;
        })
        .join("")
    : '<tr><td class="admin-empty" colspan="8">Belum ada transaksi.</td></tr>';
}

function renderTransactionActivities(activities) {
  const paymentLabels = {
    pending: "Menunggu",
    paid: "Dibayar",
    failed: "Gagal",
    refunded: "Dikembalikan",
  };
  const activityLabels = {
    order_created: "Pesanan dibuat",
    payment_status_changed: "Status pembayaran diubah",
  };
  const activityBody = document.querySelector("#admin-transaction-activities");
  activityBody.innerHTML = activities.length
    ? activities
        .map((activity) => {
          const previousStatus = activity.previousStatus
            ? paymentLabels[activity.previousStatus] || activity.previousStatus
            : "—";
          const newStatus =
            paymentLabels[activity.newStatus] || activity.newStatus;
          const activityDate = new Date(
            String(activity.createdAt).replace(" ", "T"),
          ).toLocaleString("id-ID");
          return `<tr>
            <td>${escapeHtml(activityDate)}</td>
            <td>${escapeHtml(activityLabels[activity.activityType] || "Aktivitas transaksi")}</td>
            <td><strong>ORD-${Number(activity.orderId)}</strong></td>
            <td><strong>${escapeHtml(activity.customer)}</strong><small>${escapeHtml(activity.email)}</small></td>
            <td>${escapeHtml(previousStatus)} → ${escapeHtml(newStatus)}</td>
            <td><strong>${formatRupiah(Number(activity.total))}</strong></td>
            <td>${escapeHtml(activity.actorName || "Akun dihapus")}</td>
          </tr>`;
        })
        .join("")
    : '<tr><td class="admin-empty" colspan="7">Belum ada aktivitas transaksi.</td></tr>';
}

function renderAdminTools(management = {}, reports = {}, products = []) {
  document.querySelector("#admin-flash-sale-product").innerHTML =
    '<option value="">Tanpa produk spesifik</option>' +
    products
      .map(
        (product) =>
          `<option value="${Number(product.id)}">${escapeHtml(product.name)}</option>`,
      )
      .join("");
  document.querySelector("#admin-review-product").innerHTML =
    '<option value="">Tanpa produk spesifik</option>' +
    products
      .map(
        (product) =>
          `<option value="${Number(product.id)}">${escapeHtml(product.name)}</option>`,
      )
      .join("");

  const renderRows = (selector, rows, emptyMessage, renderRow, columns) => {
    const body = document.querySelector(selector);
    body.innerHTML = rows.length
      ? rows.map(renderRow).join("")
      : `<tr><td class="admin-empty" colspan="${columns}">${escapeHtml(emptyMessage)}</td></tr>`;
  };
  const deleteButton = (type, id) =>
    `<button class="admin-tool-delete" type="button" data-tool-delete="${escapeHtml(type)}" data-tool-id="${Number(id)}">Hapus</button>`;
  const activeStatus = (type, record) =>
    moderationActions(type, record.id, Number(record.isActive) ? "1" : "0", [
      ["1", "Aktif"],
      ["0", "Nonaktif"],
    ]);
  renderRows(
    "#admin-coupons",
    management.coupons || [],
    "Belum ada kupon.",
    (coupon) => {
      const discount = coupon.discountType === "percent"
        ? `${Number(coupon.discountValue)}%`
        : formatRupiah(Number(coupon.discountValue));
      const period = [coupon.startsAt, coupon.endsAt].filter(Boolean).join(" – ") || "Tidak dibatasi";
      return `<tr><td><strong>${escapeHtml(coupon.code)}</strong></td><td>${escapeHtml(discount)}</td><td>${escapeHtml(period)}</td><td>${activeStatus("coupon", coupon)}</td><td>${deleteButton("coupon", coupon.id)}</td></tr>`;
    },
    5,
  );
  renderRows(
    "#admin-flash-sales",
    management.flashSales || [],
    "Belum ada jadwal flash sale.",
    (sale) => {
      const product = products.find(
        (item) => Number(item.id) === Number(sale.productId),
      );
      return `<tr><td>${escapeHtml(sale.title)}</td><td>${escapeHtml(product?.name || "Semua produk")}</td><td>${formatRupiah(Number(sale.salePrice))}</td><td>${escapeHtml(new Date(String(sale.startsAt).replace(" ", "T")).toLocaleString("id-ID"))}<small>s.d. ${escapeHtml(new Date(String(sale.endsAt).replace(" ", "T")).toLocaleString("id-ID"))}</small></td><td>${activeStatus("flash_sale", sale)}</td><td>${deleteButton("flash_sale", sale.id)}</td></tr>`;
    },
    6,
  );
  renderRows(
    "#admin-campaigns",
    management.campaigns || [],
    "Belum ada draft notifikasi/email.",
    (campaign) => `<tr><td>${escapeHtml(campaign.channel === "email" ? "Email" : "Notifikasi")}</td><td>${escapeHtml(campaign.subject)}</td><td>${escapeHtml(campaign.scheduledAt ? new Date(String(campaign.scheduledAt).replace(" ", "T")).toLocaleString("id-ID") : "Belum dijadwalkan")}</td><td>${escapeHtml(campaign.status === "scheduled" ? "Terjadwal" : "Draft")}</td><td>${moderationActions("campaign", campaign.id, campaign.status, [["draft", "Draft"], ["scheduled", "Terjadwal"]])}</td></tr>`,
    5,
  );
  renderRows(
    "#admin-reviews",
    management.reviews || [],
    "Belum ada ulasan.",
    (review) => `<tr><td>${escapeHtml(review.customerName)}</td><td>${Number(review.rating)} / 5</td><td><small>${escapeHtml(review.reviewText)}</small></td><td>${escapeHtml(review.status)}</td><td>${moderationActions("review", review.id, review.status, [["pending", "Menunggu"], ["published", "Tampilkan"], ["rejected", "Tolak"]])}</td></tr>`,
    5,
  );
  renderRows(
    "#admin-complaints",
    management.complaints || [],
    "Belum ada komplain.",
    (complaint) => `<tr><td><strong>${escapeHtml(complaint.customerName)}</strong><small>${escapeHtml(complaint.customerEmail)}</small></td><td>${escapeHtml(complaint.subject)}<small>${escapeHtml(complaint.message)}</small></td><td>${escapeHtml(complaint.status)}</td><td>${moderationActions("complaint", complaint.id, complaint.status, [["open", "Terbuka"], ["in_progress", "Diproses"], ["resolved", "Selesai"], ["closed", "Ditutup"]])}</td></tr>`,
    4,
  );
  renderRows(
    "#admin-abuse-reports",
    management.abuseReports || [],
    "Belum ada laporan penyalahgunaan.",
    (report) => `<tr><td>${escapeHtml(report.reporterEmail || "Anonim")}</td><td>${escapeHtml(report.targetType)}<small>${escapeHtml(report.targetReference)}</small></td><td><small>${escapeHtml(report.reason)}</small></td><td>${escapeHtml(report.status)}</td><td>${moderationActions("abuse_report", report.id, report.status, [["open", "Terbuka"], ["investigating", "Diselidiki"], ["resolved", "Selesai"], ["dismissed", "Ditolak"]])}</td></tr>`,
    5,
  );

  const reportTables = [
    ["#admin-report-monthly", reports.monthlySales || [], "period", (row) => escapeHtml(row.period), (row) => Number(row.orderCount).toLocaleString("id-ID"), (row) => formatRupiah(Number(row.revenue))],
    ["#admin-report-products", reports.topProducts || [], "productName", (row) => escapeHtml(row.productName), (row) => Number(row.quantity).toLocaleString("id-ID"), (row) => formatRupiah(Number(row.revenue))],
    ["#admin-report-customers", reports.topCustomers || [], "customerName", (row) => `${escapeHtml(row.customerName)}<small>${escapeHtml(row.customerEmail)}</small>`, (row) => Number(row.orderCount).toLocaleString("id-ID"), (row) => formatRupiah(Number(row.revenue))],
  ];
  reportTables.forEach(([selector, rows, , label, count, value]) => {
    renderRows(
      selector,
      rows,
      "Belum ada data laporan.",
      (row) => `<tr><td>${label(row)}</td><td>${count(row)}</td><td>${value(row)}</td></tr>`,
      3,
    );
  });
  renderRows(
    "#admin-report-stock",
    reports.stock || [],
    "Belum ada data stok.",
    (product) => `<tr><td>${escapeHtml(product.name)}</td><td>${escapeHtml(product.category)}</td><td>${Number(product.stock).toLocaleString("id-ID")}</td><td>${formatRupiah(Number(product.stock) * Number(product.price))}</td></tr>`,
    4,
  );
  window.adminReportData = reports;
  window.adminManagementData = management;
}

function moderationActions(type, id, selected, options) {
  return `<select class="admin-select admin-tool-status" data-tool-status="${escapeHtml(type)}" data-tool-id="${Number(id)}" aria-label="Status ${escapeHtml(type)} ${Number(id)}">
    ${options.map(([value, label]) => `<option value="${value}"${selected === value ? " selected" : ""}>${label}</option>`).join("")}
  </select>`;
}

function downloadReportCsv() {
  const type = document.querySelector("#admin-report-export").value;
  const rows = window.adminReportData?.[type] || [];
  const columns = {
    monthlySales: [["Bulan", "period"], ["Pesanan dibayar", "orderCount"], ["Pendapatan", "revenue"]],
    topProducts: [["Produk", "productName"], ["Terjual", "quantity"], ["Pendapatan", "revenue"]],
    topCustomers: [["Pelanggan", "customerName"], ["Email", "customerEmail"], ["Pesanan", "orderCount"], ["Pendapatan", "revenue"]],
    stock: [["ID", "id"], ["Produk", "name"], ["Kategori", "category"], ["Stok", "stock"], ["Harga", "price"], ["Status", "isActive"]],
  }[type];
  const csvEscape = (value) => {
    let text = String(value ?? "");
    if (/^[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replace(/"/g, '""')}"`;
  };
  const csv = [
    columns.map(([label]) => csvEscape(label)).join(","),
    ...rows.map((row) => columns.map(([, key]) => csvEscape(row[key])).join(",")),
  ].join("\r\n");
  const blob = new Blob(["\uFEFF", csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `gadgethub-${type}-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

async function refreshDashboard(successMessage) {
  if (successMessage) showToast(successMessage);
  try {
    await loadAdminDashboard();
  } catch (error) {
    showToast(
      `Perubahan tersimpan, tetapi dashboard gagal dimuat ulang: ${error.message}`,
    );
  }
}

async function initializeDashboard() {
  try {
    if (
      requestedDashboardRole !== null &&
      !["admin", "super_admin"].includes(requestedDashboardRole)
    ) {
      showAccessMessage("URL dashboard tidak valid. Kembali ke beranda GadgetHub.", true);
      return;
    }

    const session = await apiRequest("session");
    currentUser = session.user;
    if (!currentUser) {
      showAdminLogin();
      return;
    }
    if (!["admin", "super_admin"].includes(currentUser.role)) {
      showAccessMessage(
        "Akun ini bukan administrator. Keluar dari akun ini lalu masuk dengan akun administrator.",
        true,
      );
      document.querySelector("#admin-logout").hidden = false;
      return;
    }
    if (
      requestedDashboardRole !== null &&
      currentUser.role !== requestedDashboardRole
    ) {
      showAccessMessage(
        requestedDashboardRole === "super_admin"
          ? "URL ini khusus super admin. Masuk dengan akun super admin untuk melanjutkan."
          : "URL ini khusus admin. Masuk dengan akun admin untuk melanjutkan.",
        true,
      );
      document.querySelector("#admin-logout").hidden = false;
      return;
    }
    await loadAdminDashboard();
  } catch (error) {
    showAccessMessage(`Dashboard tidak dapat dimuat: ${error.message}`, true);
  }
}

document
  .querySelector("#admin-login-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('[type="submit"]');
    let lockoutActive = false;
    setAdminLoginError("admin-login", "");
    button.disabled = true;
    try {
      const email = form.elements.email.value.trim();
      const result = await apiRequest("login", {
        method: "POST",
        body: {
          email,
          password: form.elements.password.value,
          expectedRole: requestedDashboardRole,
        },
      });
      adminLoginEmail = result.email || email;
      document.querySelector("#admin-login-otp-email").textContent = adminLoginEmail;
      document.querySelector("#admin-login-otp").pattern = `[0-9]{${result.codeLength}}`;
      document.querySelector("#admin-login-otp").maxLength = result.codeLength;
      form.elements.password.value = "";
      form.hidden = true;
      document.querySelector("#admin-login-otp-form").hidden = false;
      setAdminLoginError("admin-login-otp", "");
      startOtpResendCooldown(Number(result.resendAfterSeconds) || 0);
      document.querySelector("#admin-login-otp").focus();
    } catch (error) {
      const attempts = error.remainingAttempts > 0
        ? ` Sisa percobaan: ${error.remainingAttempts}.`
        : "";
      setAdminLoginError(
        "admin-login",
        `${error.message}${attempts}`,
      );
      if (error.retryAfterSeconds > 0) {
        lockoutActive = true;
        button.disabled = true;
        window.setTimeout(() => {
          button.disabled = false;
        }, error.retryAfterSeconds * 1000);
      }
    } finally {
      if (!lockoutActive) button.disabled = false;
    }
  });

document
  .querySelector("#admin-login-otp-form")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('[type="submit"]');
    setAdminLoginError("admin-login-otp", "");
    button.disabled = true;
    try {
      const result = await apiRequest("verify-login-otp", {
        method: "POST",
        body: {
          email: adminLoginEmail,
          otp: form.elements.otp.value.trim(),
          expectedRole: requestedDashboardRole,
        },
      });
      currentUser = result.user;
      form.reset();
      await loadAdminDashboard();
    } catch (error) {
      setAdminLoginError("admin-login-otp", error.message);
    } finally {
      button.disabled = false;
    }
  });

document
  .querySelector("#admin-login-resend")
  .addEventListener("click", async (event) => {
    const button = event.currentTarget;
    setAdminLoginError("admin-login-otp", "");
    button.disabled = true;
    try {
      const result = await apiRequest("resend-login-otp", {
        method: "POST",
        body: { email: adminLoginEmail },
      });
      showToast(result.message);
      startOtpResendCooldown(Number(result.resendAfterSeconds) || 60);
    } catch (error) {
      setAdminLoginError("admin-login-otp", error.message);
      if (error.retryAfterSeconds > 0) {
        startOtpResendCooldown(error.retryAfterSeconds);
      } else {
        button.disabled = false;
      }
    }
  });

document
  .querySelector("#admin-login-back")
  .addEventListener("click", () => {
    window.clearTimeout(otpResendTimer);
    document.querySelector("#admin-login-otp-form").reset();
    document.querySelector("#admin-login-otp-form").hidden = true;
    document.querySelector("#admin-login-form").hidden = false;
    document.querySelector("#admin-login-resend").disabled = false;
    document.querySelector("#admin-login-password").value = "";
    setAdminLoginError("admin-login-otp", "");
    document.querySelector("#admin-login-email").focus();
  });

document.querySelector("#admin-logout").addEventListener("click", async () => {
  const button = document.querySelector("#admin-logout");
  button.disabled = true;
  try {
    await apiRequest("logout", { method: "POST", body: {} });
    window.location.reload();
  } catch (error) {
    showToast(`Tidak dapat keluar dari akun: ${error.message}`);
    button.disabled = false;
  }
});

document
  .querySelector("#admin-orders")
  .addEventListener("change", async (event) => {
    const select = event.target.closest("[data-order-status]");
    if (!select) return;
    select.disabled = true;
    try {
      await apiRequest("admin-order-status", {
        method: "PATCH",
        body: {
          orderId: Number(select.dataset.orderStatus),
          status: select.value,
        },
      });

      await refreshDashboard("Status pesanan berhasil diperbarui.");
    } catch (error) {
      showToast(`Status pesanan gagal diperbarui: ${error.message}`);
      await refreshDashboard();
    }
  });

document
  .querySelector("#admin-order-search")
  .addEventListener("input", renderOrderFilters);
document
  .querySelector("#admin-order-filter")
  .addEventListener("change", renderOrderFilters);

document
  .querySelector("#admin-transactions")
  .addEventListener("change", async (event) => {
    const select = event.target.closest("[data-payment-status]");
    if (!select || currentUser?.role !== "super_admin") return;
    select.disabled = true;
    try {
      const result = await apiRequest("admin-payment-status", {
        method: "PATCH",
        body: {
          orderId: Number(select.dataset.paymentStatus),
          paymentStatus: select.value,
        },
      });
      await refreshDashboard(result.message);
    } catch (error) {
      showToast(`Status pembayaran gagal diperbarui: ${error.message}`);
      await refreshDashboard();
    }
  });

document
  .querySelector("#admin-products")
  .addEventListener("change", async (event) => {
    const input = event.target.closest("[data-product-stock]");
    if (!input) return;
    const stock = Number(input.value);
    if (!Number.isInteger(stock) || stock < 0 || stock > 1000000) {
      showToast("Stok harus berupa bilangan bulat antara 0 dan 1.000.000.");
      await refreshDashboard();
      return;
    }
    input.disabled = true;
    try {
      await apiRequest("admin-product-stock", {
        method: "PATCH",
        body: { productId: Number(input.dataset.productStock), stock },
      });
      await refreshDashboard("Stok produk berhasil diperbarui.");
    } catch (error) {
      showToast(`Stok gagal diperbarui: ${error.message}`);
      await refreshDashboard();
    }
  });

document
  .querySelector("#admin-product-create")
  .addEventListener("submit", async (event) => {
    event.preventDefault();
    if (currentUser?.role !== "super_admin") return;
    const form = event.currentTarget;
    const button = form.querySelector('[type="submit"]');
    const values = Object.fromEntries(new FormData(form));
    values.price = Number(values.price);
    values.stock = Number(values.stock);
    values.isActive = true;
    button.disabled = true;
    try {
      const result = await apiRequest("admin-product-save", {
        method: "POST",
        body: values,
      });
      form.reset();
      form.elements.stock.value = "0";
      await refreshDashboard(result.message);
    } catch (error) {
      showToast(`Produk gagal ditambahkan: ${error.message}`);
    } finally {
      button.disabled = false;
    }
  });

document
  .querySelector("#admin-products")
  .addEventListener("click", async (event) => {
    const button = event.target.closest("[data-save-product]");
    if (!button || currentUser?.role !== "super_admin") return;
    const row = button.closest("[data-product-row]");
    const values = {};
    row.querySelectorAll("[data-product-field]").forEach((field) => {
      values[field.dataset.productField] =
        field.type === "checkbox" ? field.checked : field.value.trim();
    });
    values.productId = Number(button.dataset.saveProduct);
    values.price = Number(values.price);
    values.stock = Number(row.querySelector("[data-product-stock]").value);
    button.disabled = true;
    try {
      const result = await apiRequest("admin-product-save", {
        method: "PATCH",
        body: values,
      });
      await refreshDashboard(result.message);
    } catch (error) {
      showToast(`Produk gagal disimpan: ${error.message}`);
    } finally {
      button.disabled = false;
    }
  });

document
  .querySelector("#admin-users")
  .addEventListener("change", async (event) => {
    const roleSelect = event.target.closest("[data-user-role]");
    const activeSelect = event.target.closest("[data-user-active]");
    if (!roleSelect && !activeSelect) return;
    const select = roleSelect || activeSelect;
    select.disabled = true;
    try {
      const result = roleSelect
        ? await apiRequest("admin-user-role", {
            method: "PATCH",
            body: {
              userId: Number(select.dataset.userRole),
              role: select.value,
            },
          })
        : await apiRequest("admin-user-status", {
            method: "PATCH",
            body: {
              userId: Number(select.dataset.userActive),
              isActive: select.value === "1",
            },
          });
      await refreshDashboard(result.message);
    } catch (error) {
      showToast(`Perubahan akun gagal: ${error.message}`);
      await refreshDashboard();
    }
  });

document.addEventListener("submit", async (event) => {
  const form = event.target.closest(".admin-tool-form");
  if (!form || currentUser?.role !== "super_admin") return;
  event.preventDefault();
  const button = form.querySelector('[type="submit"]');
  const type = form.dataset.toolType;
  const data = Object.fromEntries(new FormData(form));
  if (type === "coupon") {
    data.discountValue = Number(data.discountValue);
    if (data.usageLimit !== "") data.usageLimit = Number(data.usageLimit);
  }
  if (type === "review") data.rating = Number(data.rating);
  if (type === "review" && data.productId === "") data.productId = null;
  if (type === "flash_sale" && data.productId === "") data.productId = null;
  button.disabled = true;
  try {
    const result = await apiRequest("admin-tool-save", {
      method: "POST",
      body: { type, data },
    });
      form.reset();
      await refreshDashboard(result.message);
  } catch (error) {
    showToast(`Data gagal disimpan: ${error.message}`);
  } finally {
    button.disabled = false;
  }
});

document.addEventListener("change", async (event) => {
  const select = event.target.closest("[data-tool-status]");
  if (!select || currentUser?.role !== "super_admin") return;
  select.disabled = true;
  try {
    const result = await apiRequest("admin-tool-status", {
      method: "PATCH",
      body: {
        type: select.dataset.toolStatus,
        id: Number(select.dataset.toolId),
        status: select.value,
      },
    });
    await refreshDashboard(result.message);
  } catch (error) {
    showToast(`Status gagal diperbarui: ${error.message}`);
    await refreshDashboard();
  }
});

document.addEventListener("click", async (event) => {
  const deleteButton = event.target.closest("[data-tool-delete]");
  if (deleteButton && currentUser?.role === "super_admin") {
    const type = deleteButton.dataset.toolDelete;
    const id = Number(deleteButton.dataset.toolId);
    if (!window.confirm("Hapus data ini? Tindakan ini tidak dapat dibatalkan.")) {
      return;
    }
    deleteButton.disabled = true;
    try {
      const result = await apiRequest("admin-tool-delete", {
        method: "DELETE",
        body: { type, id },
      });
      await refreshDashboard(result.message);
    } catch (error) {
      showToast(`Data gagal dihapus: ${error.message}`);
      await refreshDashboard();
    }
    return;
  }

  if (event.target.closest("#admin-report-download")) downloadReportCsv();
});

initializeDashboard();
