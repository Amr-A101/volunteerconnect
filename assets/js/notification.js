function loadNotifCount() {
    fetch("functions/fetch_notif_count.php")
        .then(res => res.json())
        .then(data => {
            const badge = document.getElementById("notifCount");
            if (data.count > 0) {
                badge.style.display = "inline";
                badge.textContent = data.count;
            } else {
                badge.style.display = "none";
            }
        });
}

setInterval(loadNotifCount, 5000);
loadNotifCount();

document.addEventListener("DOMContentLoaded", function() {
    const bell = document.getElementById("notifBell");
    const dropdown = document.getElementById("notifDropdown");
    const notifCount = document.getElementById("notifCount");
    const notifList = document.getElementById("notifList");

    // Toggle dropdown visibility
    bell.addEventListener("click", () => {
        const isVisible = dropdown.style.display === "block";
        dropdown.style.display = isVisible ? "none" : "block";

        if (!isVisible) {
            fetch("functions/fetch_notifications.php?mark_read=1")
                .then(() => {
                    notifCount.style.display = "none";
                });
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener("click", (e) => {
        if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = "none";
        }
    });

    // Fetch notifications
    function fetchNotifications() {
        fetch("functions/fetch_notifications.php")
            .then(res => res.json())
            .then(data => {
                notifList.innerHTML = "";

                const seeMoreBtn = document.createElement("div");
                seeMoreBtn.className = "notif-item";
                seeMoreBtn.style.fontWeight = "bold";
                seeMoreBtn.style.textAlign = "center";
                seeMoreBtn.style.background = "#f0f0f0";
                seeMoreBtn.style.marginBottom = "5px";
                seeMoreBtn.textContent = "See More Notifications";
                seeMoreBtn.addEventListener("click", () => {
                    window.location.href = "notifications.php";
                });
                notifList.appendChild(seeMoreBtn);

                if (data.notifications.length === 0) {
                    notifList.innerHTML += "<p class='notif-empty'>No notifications yet.</p>";
                } else {
                    data.notifications.forEach(n => {
                        const item = document.createElement("div");
                        item.className = "notif-item";
                        item.innerHTML = `<b>${n.title}</b><br>${n.body}<br><small>${n.created_at}</small>`;
                        item.addEventListener("click", () => {
                            window.location.href = n.link;
                        });
                        notifList.appendChild(item);
                    });
                }

                notifCount.textContent = data.unread_count;
                notifCount.style.display = data.unread_count > 0 ? "inline" : "none";
            });
    }

    // Fetch every 10s
    fetchNotifications();
    setInterval(fetchNotifications, 10000);
});
