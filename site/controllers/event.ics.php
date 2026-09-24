<?php

use Kirby\Exception\NotFoundException;

return function ($page) {
	if ($page->isPublic() === false) {
		throw new NotFoundException();
	}

	return [];
};
