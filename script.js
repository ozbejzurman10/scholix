document.addEventListener("DOMContentLoaded", function () {

    // Kartice modulov so že <a> elementi, ne potrebujejo JS handlera.


    // ==============================
    // PRIKAZ / SKRIVANJE GESLA
    // ==============================

    const passwordButtons =
        document.querySelectorAll(".password-toggle");

    passwordButtons.forEach(function (button) {

        button.addEventListener("click", function () {

            const input =
                document.getElementById(button.dataset.target);

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


    // ==============================
    // REGISTRACIJA
    // ==============================

    const registerForm =
        document.querySelector(".auth-form");

    if (
        registerForm &&
        document.getElementById("password_confirm")
    ) {

        registerForm.addEventListener("submit", function (event) {

            const password =
                document.getElementById("reg_password").value;

            const confirm =
                document.getElementById("password_confirm").value;

            if (password !== confirm) {

                event.preventDefault();

                alert("Gesli se ne ujemata.");

            }

        });

    }

});
