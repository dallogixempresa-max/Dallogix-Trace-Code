export function setFormFeedback(form, message = "", tone = "") {
  const feedback = form?.querySelector("[data-form-feedback]");
  if (!feedback) return;

  feedback.textContent = message;
  feedback.hidden = !message;
  feedback.className = `form-feedback${tone ? ` is-${tone}` : ""}`;
}
