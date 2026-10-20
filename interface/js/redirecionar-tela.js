const script = document.querySelector("script[data-target]");
const target = script?.dataset.target;
if (target) window.location.replace(`${target}${window.location.search}${window.location.hash}`);
