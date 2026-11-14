function checkEmptyList(list, files) {
    if (files.length > 0) return;
    const message = document.createElement("p");
    message.classList.add("message");
    message.innerHTML = "you don't have any favorites yet. press <button class='button'><img src='/static/img/icons/star-gray.png' alt='favorite' title='favorite this file'></button> next time you see a file!";
    list.append(message);
}

function renderFavoriteFiles(favoriteFiles) {
    favoriteFiles.replaceChildren();

    const files = getFavoriteFiles();

    for (let file of files) {
        file.is_favorite = true;
        file.save = () => saveFile(file, files, "favorite-files");
        favoriteFiles.append(createFile(file));
    }

    checkEmptyList(favoriteFiles, files);
}

window.addEventListener("DOMContentLoaded", () => {
    const favoriteFiles = document.getElementById("favorite-files");
    if (!favoriteFiles) {
        return;
    }

    renderFavoriteFiles(favoriteFiles);

    window.addEventListener("account-data-load", (e) => {
        if (e.detail.sections.includes("favorites")) renderFavoriteFiles(favoriteFiles);
    });
});
