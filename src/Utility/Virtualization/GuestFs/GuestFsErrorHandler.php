<?php

namespace Datto\Utility\Virtualization\GuestFs;

/**
 * A simple abstract class that holds error handling code for the GuestFs library.
 */
abstract class GuestFsErrorHandler
{
    /** Function that anyone implementing a GuestFsErrorHandler must implement */
    abstract protected function getHandle();

    /**
     * Wrapper around a library call. If the result is `false`, which implies a library error,
     * this will throw a RuntimeException with the error message from the library.
     *
     * @param mixed $ret The return code from a library call
     * @return mixed The passed-in value, if it isn't `false`
     */
    protected function throwOnFalse($ret)
    {
        if (false === $ret) {
            $this->throwLastError();
        }
        return $ret;
    }

    /**
     * Calls a function that may return an error, with which we have no way (in the PHP bindings) of
     * determining if this is the case (ex: guestfs_is_lv() returns false if the partition is not a
     * logical volume, and also returns false if there is an error). This should be used in the
     * case of guestfs functions that are expected to return a boolean in success scenarios. Other
     * functions should use `throwOnFalse`.
     *
     * @param callable $func A function closure to call that may generate an error
     * @return mixed The passed in value, if no error was detected
     */
    protected function throwOnNewError(callable $func)
    {
        // NOTE: This is a debug API, designed to be used during libguestfs regression testing, to
        // verify the last_error functionality. It sets the last_error to a string of the given length
        // (5 in this case) and fills it with `a`s. This is perfect for our needs here of a
        // non-destructive way to reset the error to a known value before we make a call.
        guestfs_debug($this->getHandle(), "error", ["5"]);
        $cachedError = $this->getLastError();

        // Make the call provided
        $ret = $func();

        // If there is a new error returned by the library, it means the call to func() generated
        // a library error, so throw it.
        if ($cachedError !== $this->getLastError()) {
            $this->throwLastError();
        }
        return $ret;
    }

    /**
     * Throws an exception with the details populated from the most recent error returned
     * by the underlying libguestfs
     *
     * @throws GuestFsException
     */
    private function throwLastError()
    {
        throw new GuestFsException("GuestFs Error: " . $this->getLastError());
    }

    /**
     * Get the most recent error from the underlying library
     *
     * @return string The error string from libguestfs
     */
    private function getLastError(): string
    {
        return guestfs_last_error($this->getHandle()) ?: 'error calling guestfs_last_error';
    }
}
