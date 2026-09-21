document.addEventListener("DOMContentLoaded", function () {
    // Klik na kartico modula
    const cards = document.querySelectorAll(".module-card");
    const toast = document.getElementById("toast");

    cards.forEach(function (card) {
        card.addEventListener("click", function () {
            const moduleName = card.dataset.module;

            if (toast) {
                toast.textContent = moduleName + " – modul je pripravljen za povezavo z aplikacijo.";
                toast.classList.add("show");

                setTimeout(function () {
                    toast.classList.remove("show");
                }, 2400);
            }
        });
    });

    // Prikaz / skrivanje gesla
    const passwordButtons = document.querySelectorAll(".password-toggle");

    passwordButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const input = document.getElementById(button.dataset.target);

            if (!input) return;

            if (input.type === "password") {
                input.type = "text";
                button.textContent = "Skrij";
            } else {
                input.type = "password";
                button.textContent = "Prikaži";
            }
        });
    });

    // Osnovna kontrola registracije
    const registerForm = document.querySelector(".auth-form");
    if (registerForm && document.getElementById("password_confirm")) {
        registerForm.addEventListener("submit", function (event) {
            const password = document.getElementById("reg_password").value;
            const confirm = document.getElementById("password_confirm").value;

            if (password !== confirm) {
                event.preventDefault();
                alert("Gesli se ne ujemata.");
            }
        });
    }
});
