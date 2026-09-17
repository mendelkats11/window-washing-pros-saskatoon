// ==========================================================================
// Window Washing Pros of Saskatoon — site script
// ==========================================================================

document.addEventListener("DOMContentLoaded", () => {
  initHeader();
  initMobileNav();
  initRevealAnimations();
  initFaq();
  initContactForm();
  initYear();
});

/* ---- Header: solid background after scrolling past hero ---- */
function initHeader() {
  const header = document.querySelector(".site-header");
  if (!header) return;

  const hasHero = document.querySelector(".hero, .page-hero");
  if (!hasHero) {
    header.classList.add("solid");
  }

  const onScroll = () => {
    if (window.scrollY > 40) {
      header.classList.add("solid");
    } else if (hasHero) {
      header.classList.remove("solid");
    }
  };
  onScroll();
  window.addEventListener("scroll", onScroll, { passive: true });
}

/* ---- Mobile nav toggle ---- */
function initMobileNav() {
  const toggle = document.querySelector(".nav-toggle");
  const body = document.body;
  if (!toggle) return;

  toggle.addEventListener("click", () => {
    body.classList.toggle("nav-open");
  });

  document.querySelectorAll(".main-nav a").forEach((link) => {
    link.addEventListener("click", () => body.classList.remove("nav-open"));
  });
}

/* ---- Scroll reveal animations ---- */
function initRevealAnimations() {
  const items = document.querySelectorAll(".reveal");
  if (!items.length) return;

  if (!("IntersectionObserver" in window)) {
    items.forEach((el) => el.classList.add("is-visible"));
    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add("is-visible");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: "0px 0px -60px 0px" }
  );

  items.forEach((el) => observer.observe(el));
}

/* ---- FAQ accordion ---- */
function initFaq() {
  const items = document.querySelectorAll(".faq-item");
  items.forEach((item) => {
    const question = item.querySelector(".faq-question");
    const answer = item.querySelector(".faq-answer");
    if (!question || !answer) return;

    question.addEventListener("click", () => {
      const isOpen = item.classList.contains("open");

      items.forEach((other) => {
        other.classList.remove("open");
        other.querySelector(".faq-answer").style.maxHeight = null;
      });

      if (!isOpen) {
        item.classList.add("open");
        answer.style.maxHeight = answer.scrollHeight + "px";
      }
    });
  });
}

/* ---- Contact / quote form ----
   Submits to /api/send-quote.php, a plain PHP endpoint (runs on ordinary
   Hostinger shared hosting — no Node "web app" needed) that emails the
   request via Resend. The Resend API key lives only in api/config.local.php
   on the server and is never exposed to the browser. */
function initContactForm() {
  const form = document.getElementById("quote-form");
  if (!form) return;

  const successBox = document.getElementById("form-success");
  const errorBox = document.getElementById("form-error");

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    if (errorBox) errorBox.classList.remove("show");

    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = "Sending&hellip;";

    const payload = {
      name: form.name.value.trim(),
      phone: form.phone.value.trim(),
      email: form.email.value.trim(),
      service: form.service.value,
      message: form.message.value.trim(),
    };

    try {
      const res = await fetch("/api/send-quote.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }

      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.error) || `Request failed (${res.status})`);
      }

      form.classList.add("hide");
      if (successBox) successBox.classList.add("show");
      form.reset();
      if (successBox) successBox.scrollIntoView({ behavior: "smooth", block: "center" });
    } catch (err) {
      console.error("Quote form submission failed:", err);
      if (errorBox) {
        errorBox.classList.add("show");
        errorBox.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }
  });
}

/* ---- Footer year ---- */
function initYear() {
  const el = document.getElementById("year");
  if (el) el.textContent = new Date().getFullYear();
}
