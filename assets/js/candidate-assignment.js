document.addEventListener("DOMContentLoaded", () => {
    const electionSelect = document.querySelector("#candidate_election_id");
    const positionSelect = document.querySelector("#candidate_position_id");
    if (!(electionSelect instanceof HTMLSelectElement) || !(positionSelect instanceof HTMLSelectElement)) {
        return;
    }

    const updatePositions = () => {
        const electionId = electionSelect.value;
        const available = Array.from(positionSelect.options).filter((option) => {
            const matches = option.dataset.electionId === electionId;
            option.hidden = !matches;
            option.disabled = !matches;
            return matches;
        });
        if (available.length > 0 && !available.includes(positionSelect.selectedOptions[0])) {
            positionSelect.value = available[0].value;
        }
    };

    electionSelect.addEventListener("change", updatePositions);
    updatePositions();
});
