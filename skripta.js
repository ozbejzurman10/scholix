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
    const regPass =
        document.getElementById("reg_pass");
    const regPass2 =
        document.getElementById("reg_pass2");

    if (registerForm && regPass && regPass2) {

        registerForm.addEventListener("submit", function (event) {

            if (regPass.value !== regPass2.value) {

                event.preventDefault();

                alert("Gesli se ne ujemata.");

            }

        });

    }

});
