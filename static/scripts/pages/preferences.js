window.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("form");
    if (!form) return;

    form.addEventListener("reset", (e) => {
        e.preventDefault();
        document.getElementById("noloop").checked = false;
        document.getElementById("noautoplay").checked = false;
        document.getElementById("theme").checked = false;

        const themeSelector = document.getElementById("theme");
        themeSelector.value = "<?= CONFIG['instance']['defaultstyle'] ?>";
        themeSelector.dispatchEvent(new Event("change", { bubbles: true }));
    });
});